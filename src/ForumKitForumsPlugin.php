<?php

declare(strict_types=1);

namespace ForumKit\Forums;

use Filament\Contracts\Plugin;
use Filament\Panel;
use ForumKit\Forums\Filament\Pages\ModerationQueue;
use ForumKit\Forums\Filament\Resources\ForumGroups\ForumGroupResource;
use ForumKit\Forums\Filament\Resources\Forums\ForumResource;
use ForumKit\Forums\Filament\Resources\PostReports\PostReportResource;
use ForumKit\Forums\Filament\Resources\Posts\PostResource;
use ForumKit\Forums\Filament\Resources\Threads\ThreadResource;
use ForumKit\Forums\Filament\Widgets\ModerationQueueStats;

class ForumKitForumsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'forumkit-forums';
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
            ])
            ->pages([ModerationQueue::class])
            ->widgets([ModerationQueueStats::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
