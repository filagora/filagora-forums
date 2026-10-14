<?php

declare(strict_types=1);

namespace Filagora\Forums;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Navigation\NavigationGroupDefinition;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Core\Privacy\TableRetentionRule;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Settings\SettingDefinition;
use Filagora\Core\Settings\SettingsRegistry;
use Filagora\Core\Settings\SettingType;
use Filagora\Core\Support\GateAbilities;
use Filagora\Core\Support\Tables;
use Filagora\Entitlements\Registry\Definition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Forums\Commands\SyncForumStatsCommand;
use Filagora\Forums\Commands\SyncThreadStatsCommand;
use Filagora\Forums\Contracts\ContentModerationPolicy;
use Filagora\Forums\Contracts\PaidAccessCheck;
use Filagora\Forums\Contracts\PostSignatureResolver;
use Filagora\Forums\Contracts\StaffPreview;
use Filagora\Forums\Observers\ForumStatsObserver;
use Filagora\Forums\Privacy\DismissedReportsRetention;
use Filagora\Forums\Privacy\ForumsDataContributor;
use Filagora\Forums\Services\ActiveAnnouncements;
use Filagora\Forums\Services\AnnouncementDismissals;
use Filagora\Forums\Services\ApprovalRules;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\ForumStats;
use Filagora\Forums\Services\Polls;
use Filagora\Forums\Services\Posting;
use Filagora\Forums\Services\PostModeration;
use Filagora\Forums\Services\PostRelocation;
use Filagora\Forums\Services\PostReports;
use Filagora\Forums\Services\ReplyCreation;
use Filagora\Forums\Services\ThreadCreation;
use Filagora\Forums\Services\ThreadModeration;
use Filagora\Forums\Services\ThreadStats;
use Filagora\Forums\Support\AlwaysStaffPreview;
use Filagora\Forums\Support\ForumEntitlementKeys;
use Filagora\Forums\Support\Models;
use Filagora\Forums\Support\NoContentModeration;
use Filagora\Forums\Support\NoPaidAccess;
use Filagora\Forums\Support\NoPostSignature;
use Filagora\Forums\Targeting\Rules\BrowsingForumRule;
use Filagora\Forums\Targeting\Rules\DateIsRule;
use Filagora\Forums\Targeting\Rules\EntitlementRule;
use Filagora\Forums\Targeting\Rules\NeverPostedRule;
use Filagora\Forums\Targeting\Rules\NotPostedDaysRule;
use Filagora\Forums\Targeting\Rules\PostCountBetweenRule;
use Filagora\Forums\Targeting\Rules\TimeBetweenRule;
use Filagora\Forums\Targeting\Rules\UsernameRule;
use Filagora\Forums\Targeting\TargetingRules;
use Illuminate\Support\ServiceProvider;

class ForumsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-forums.php', 'filagora-forums');

        $this->app->bindIf(PaidAccessCheck::class, NoPaidAccess::class);
        $this->app->bindIf(StaffPreview::class, AlwaysStaffPreview::class);
        $this->app->bindIf(ContentModerationPolicy::class, NoContentModeration::class);
        $this->app->bindIf(PostSignatureResolver::class, NoPostSignature::class);
        $this->app->scoped(ForumAccess::class);
        $this->app->singleton(ForumStats::class);
        $this->app->singleton(ThreadStats::class);
        $this->app->singleton(ApprovalRules::class);
        $this->app->singleton(ThreadCreation::class);
        $this->app->singleton(ReplyCreation::class);
        $this->app->singleton(Posting::class);
        $this->app->singleton(ThreadModeration::class);
        $this->app->singleton(PostModeration::class);
        $this->app->singleton(PostRelocation::class);
        $this->app->singleton(PostReports::class);
        $this->app->singleton(Polls::class);
        $this->app->singleton(ActiveAnnouncements::class);
        $this->app->singleton(AnnouncementDismissals::class);
        $this->app->singleton(TargetingRules::class, function ($app): TargetingRules {
            $rules = new TargetingRules;

            foreach ([
                EntitlementRule::class, UsernameRule::class, DateIsRule::class, TimeBetweenRule::class,
                NeverPostedRule::class, PostCountBetweenRule::class, NotPostedDaysRule::class, BrowsingForumRule::class,
            ] as $rule) {
                $rules->register($app->make($rule));
            }

            return $rules;
        });
    }

    private function registerNavigationGroups(): void
    {
        $this->app->make(NavigationGroupRegistry::class)->register(new NavigationGroupDefinition(
            key: 'forum',
            label: fn (): string => __('filagora-forums::messages.navigation_groups.forum'),
            icon: 'heroicon-o-chat-bubble-left-right',
            sort: 10,
        ));
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('forums', 'Filagora Forums', 'filagora/filagora-forums', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('forums', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->registerNavigationGroups();
        $this->declareEntitlements();
        $this->defineGates();
        $this->observeModels();
        $this->registerPrivacy();
        $this->declareSettings();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-forums');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filagora-forums');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncForumStatsCommand::class, SyncThreadStatsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-forums.php' => config_path('filagora-forums.php'),
            ], 'filagora-forums-config');
        }
    }

    /**
     * The settings the forum reads, with their defaults (keys and types match the live forum).
     */
    private function declareSettings(): void
    {
        $settings = $this->app->make(SettingsRegistry::class);

        $settings->define(new SettingDefinition(
            'forum_post_edit_window_minutes',
            SettingType::Integer,
            30,
            label: 'Forum Post Edit Window Minutes',
            description: 'How long regular users may edit their own forum posts after posting. Staff are not limited by this window.',
        ));

        $settings->define(new SettingDefinition(
            'forum_post_edit_grace_period_minutes',
            SettingType::Integer,
            5,
            label: 'Forum Post Edit Grace Period Minutes',
            description: 'Edits made within this many minutes of posting are not marked as edited.',
        ));
    }

    /**
     * What the forums contribute to export, erasure and retention, for the compliance package
     * (or any host) to run. Nothing here needs that package installed.
     */
    private function registerPrivacy(): void
    {
        $this->app->make(PrivacyContributorRegistry::class)->register(new ForumsDataContributor);

        $retention = $this->app->make(RetentionRuleRegistry::class);

        foreach (['thread_moderation_logs', 'post_moderation_logs'] as $log) {
            $retention->register(new TableRetentionRule(
                "forums.{$log}",
                Tables::name($log),
                'created_at',
                static fn (): int => (int) config("filagora-forums.retention.{$log}_days", 1095),
            ));
        }

        $retention->register(new DismissedReportsRetention);
    }

    /**
     * Keep forum counters right as threads and posts change.
     */
    private function observeModels(): void
    {
        foreach (['thread', 'post'] as $key) {
            Models::class($key)::observe(ForumStatsObserver::class);
        }
    }

    /**
     * With `filagora-entitlements` installed: the entitlements the abilities map to, and the keys
     * set on forums, are declared so `filagora:entitlements:sync` creates their definitions.
     * Without it there is nothing to declare them to.
     */
    private function declareEntitlements(): void
    {
        if (! class_exists(EntitlementRegistry::class) || ! $this->app->bound(EntitlementRegistry::class)) {
            return;
        }

        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (GateAbilities::keys('filagora-forums.gates') as $entitlement) {
            $registry->define(new Definition($entitlement, wildcardChildren: false));
        }

        $registry->keySource(new ForumEntitlementKeys);
    }

    /**
     * Each ability is allowed when the access checker allows its mapped key.
     */
    private function defineGates(): void
    {
        GateAbilities::define('filagora-forums.gates');
    }
}
