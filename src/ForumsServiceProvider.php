<?php

declare(strict_types=1);

namespace ForumKit\Forums;

use ForumKit\Core\Modules\Module;
use ForumKit\Core\Modules\ModuleRegistry;
use ForumKit\Core\Schema\PackageSchema;
use ForumKit\Core\Schema\SchemaRegistry;
use ForumKit\Entitlements\Registry\Definition;
use ForumKit\Entitlements\Registry\EntitlementRegistry;
use ForumKit\Entitlements\Services\AuthorizationService;
use ForumKit\Forums\Contracts\PaidAccessCheck;
use ForumKit\Forums\Contracts\StaffPreview;
use ForumKit\Forums\Services\ForumAccess;
use ForumKit\Forums\Support\AlwaysStaffPreview;
use ForumKit\Forums\Support\ForumEntitlementKeys;
use ForumKit\Forums\Support\NoPaidAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ForumsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/forumkit-forums.php', 'forumkit-forums');

        $this->app->bindIf(PaidAccessCheck::class, NoPaidAccess::class);
        $this->app->bindIf(StaffPreview::class, AlwaysStaffPreview::class);
        $this->app->scoped(ForumAccess::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('forums', 'ForumKit Forums', 'zcwilt/forumkit-forums', requires: ['core', 'entitlements']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('forums', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->declareEntitlements();
        $this->defineGates();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'forumkit-forums');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'forumkit-forums');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/forumkit-forums.php' => config_path('forumkit-forums.php'),
            ], 'forumkit-forums-config');
        }
    }

    /**
     * The entitlements the staff abilities map to, and the keys set on forums.
     */
    private function declareEntitlements(): void
    {
        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (config('forumkit-forums.gates', []) as $entitlement) {
            if (is_string($entitlement) && $entitlement !== '') {
                $registry->define(new Definition($entitlement, wildcardChildren: false));
            }
        }

        $registry->keySource(new ForumEntitlementKeys);
    }

    /**
     * Each staff ability is allowed when the user holds the mapped entitlement.
     */
    private function defineGates(): void
    {
        foreach (config('forumkit-forums.gates', []) as $ability => $entitlement) {
            if (! is_string($ability) || ! is_string($entitlement)) {
                continue;
            }

            Gate::define($ability, fn (?Authenticatable $user): bool => $this->app->make(AuthorizationService::class)->allows($user, $entitlement));
        }
    }
}
