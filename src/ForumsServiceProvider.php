<?php

declare(strict_types=1);

namespace ForumKit\Forums;

use ForumKit\Core\Modules\Module;
use ForumKit\Core\Modules\ModuleRegistry;
use ForumKit\Core\Schema\PackageSchema;
use ForumKit\Core\Schema\SchemaRegistry;
use Illuminate\Support\ServiceProvider;

class ForumsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/forumkit-forums.php', 'forumkit-forums');
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('forums', 'ForumKit Forums', 'zencart/forumkit-forums', requires: ['core', 'entitlements']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('forums', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'forumkit-forums');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'forumkit-forums');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/forumkit-forums.php' => config_path('forumkit-forums.php'),
            ], 'forumkit-forums-config');
        }
    }
}
