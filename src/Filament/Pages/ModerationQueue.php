<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Pages;

use BackedEnum;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Filament\Actions\PostActions;
use Filagora\Forums\Filament\Actions\ResolveReportAction;
use Filagora\Forums\Filament\Actions\ThreadActions;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Abilities;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Everything waiting for a moderator: threads and replies held for approval, and replies with
 * open reports (one row per reply, however many reports it has).
 */
class ModerationQueue extends Page
{
    public const LIMIT = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'forum-moderation-queue';

    protected string $view = 'filagora-forums::pages.moderation-queue';

    /** @var Collection<int, Thread> */
    public Collection $pendingThreads;

    /** @var Collection<int, Post> */
    public Collection $pendingPosts;

    /** @var Collection<int, Post> */
    public Collection $reportedPosts;

    public static function canAccess(): bool
    {
        return Gate::allows(Abilities::MODERATE);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('queues');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-forums::messages.queue.navigation_label');
    }

    public function getTitle(): string
    {
        return __('filagora-forums::messages.queue.title');
    }

    public function mount(): void
    {
        $this->loadQueue();
    }

    public function approveThreadAction(): Action
    {
        return ThreadActions::approve()->after(fn () => $this->loadQueue());
    }

    public function deleteThreadAction(): Action
    {
        return ThreadActions::delete()->after(fn () => $this->loadQueue());
    }

    public function approvePostAction(): Action
    {
        return PostActions::approve()->after(fn () => $this->loadQueue());
    }

    public function rejectPostAction(): Action
    {
        return PostActions::reject()->after(fn () => $this->loadQueue());
    }

    public function deletePostAction(): Action
    {
        return PostActions::delete()->after(fn () => $this->loadQueue());
    }

    public function resolveReportAction(): Action
    {
        return ResolveReportAction::make()->after(fn () => $this->loadQueue());
    }

    public function loadQueue(): void
    {
        $this->pendingThreads = Thread::query()
            ->with(['forum', 'author'])
            ->where('moderation_status', ContentModerationStatus::PENDING->value)
            ->oldest()
            ->limit(self::LIMIT)
            ->get();

        // A pending thread's opening post is approved with the thread, so only replies to
        // approved threads are queued on their own.
        $this->pendingPosts = Post::query()
            ->with(['thread', 'author'])
            ->where('moderation_status', ContentModerationStatus::PENDING->value)
            ->where('is_deleted', false)
            ->whereHas('thread', fn ($query) => $query->where('moderation_status', ContentModerationStatus::APPROVED->value))
            ->oldest()
            ->limit(self::LIMIT)
            ->get();

        $postIds = PostReport::query()
            ->where('status', PostReportStatus::OPEN->value)
            ->distinct()
            ->limit(self::LIMIT)
            ->pluck('post_id');

        $this->reportedPosts = Post::query()
            ->with(['thread', 'author'])
            ->withCount(['reports as open_reports_count' => fn ($query) => $query->where('status', PostReportStatus::OPEN->value)])
            ->whereIn('id', $postIds)
            ->get();
    }
}
