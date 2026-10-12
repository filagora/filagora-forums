<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Enums\PostReportStatus;
use ForumKit\Forums\Filament\Pages\ModerationQueue;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\PostReport;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Support\Abilities;
use Illuminate\Support\Facades\Gate;

/**
 * What is waiting in the moderation queue, for a dashboard.
 */
class ModerationQueueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return Gate::allows(Abilities::MODERATE);
    }

    protected function getStats(): array
    {
        $threads = Thread::query()->where('moderation_status', ContentModerationStatus::PENDING->value)->count();
        $posts = Post::query()
            ->where('moderation_status', ContentModerationStatus::PENDING->value)
            ->where('is_deleted', false)
            ->whereHas('thread', fn ($query) => $query->where('moderation_status', ContentModerationStatus::APPROVED->value))
            ->count();
        $reports = PostReport::query()->where('status', PostReportStatus::OPEN->value)->distinct()->count('post_id');
        $waiting = $threads + $posts;

        return [
            Stat::make(__('forumkit-forums::messages.widget.waiting'), (string) $waiting)
                ->description(__('forumkit-forums::messages.widget.waiting_description', ['threads' => $threads, 'posts' => $posts]))
                ->url(ModerationQueue::getUrl())
                ->color($waiting > 0 ? 'danger' : 'success'),
            Stat::make(__('forumkit-forums::messages.widget.reported'), (string) $reports)
                ->description(trans_choice('forumkit-forums::messages.widget.reported_description', $reports, ['count' => $reports]))
                ->url(ModerationQueue::getUrl())
                ->color($reports > 0 ? 'danger' : 'success'),
        ];
    }
}
