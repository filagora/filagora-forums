<?php

declare(strict_types=1);

namespace Filagora\Forums;

use Filagora\Forums\Filament\Pages\ModerationQueue;
use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;
use Filagora\Forums\Filament\Resources\Forums\ForumResource;
use Filagora\Forums\Filament\Resources\PostReports\PostReportResource;
use Filagora\Forums\Filament\Resources\Posts\PostResource;
use Filagora\Forums\Filament\Resources\SiteNotifications\SiteNotificationResource;
use Filagora\Forums\Filament\Resources\Threads\ThreadResource;
use Filagora\Forums\Filament\Widgets\ModerationQueueStats;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FilagoraForumsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filagora-forums';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                ForumGroupResource::class,
                ForumResource::class,
                ThreadResource::class,
                PostResource::class,
                PostReportResource::class,
                SiteNotificationResource::class,
            ])
            ->pages([ModerationQueue::class])
            ->widgets([ModerationQueueStats::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
