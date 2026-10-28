<?php

declare(strict_types=1);

namespace Filagora\Forums\Search;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Support\ForumViewerScope;
use Filagora\Forums\Support\Models;
use Filagora\Search\Contracts\Filter;
use Filagora\Search\Enums\SortDirection;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Range;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\Sort;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The forum search. `threads()` finds threads the viewer may see: visibility becomes a filter
 * built from the viewer's `ForumViewerScope` (so no engine re-implements it), the engine returns
 * ids, and the rows are loaded and re-checked, because an index is only as fresh as its last sync.
 * `unapproved()` and `posts()` are database queries by nature.
 */
class ForumSearch
{
    public const PER_PAGE = 20;

    private const UNAPPROVED_LIMIT = 20;

    /** How many of the most recently active matching unapproved threads get text-searched. */
    private const UNAPPROVED_CANDIDATE_LIMIT = 2000;

    public function __construct(private readonly Search $search, private readonly ForumAccess $access) {}

    /**
     * @return LengthAwarePaginator<int, Thread>
     */
    public function threads(ForumSearchCriteria $criteria, ?Authenticatable $viewer, int $page = 1): LengthAwarePaginator
    {
        $scope = $this->access->forumScopeFor($viewer);
        $viewerId = $viewer?->getAuthIdentifier();

        if (! $this->canSeeAnything($scope, $viewerId)) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE, $page);
        }

        $result = $this->search->search(ThreadSearchIndex::NAME, new SearchQuery(
            text: $criteria->query,
            filter: $this->filter($criteria, $scope, $viewerId),
            sort: $this->sort($criteria),
            page: max(1, $page),
            perPage: self::PER_PAGE,
            matching: $criteria->matching,
        ));

        $rows = Models::class('thread')::query()
            ->with(['forum', 'author', 'lastPostAuthor'])
            ->whereIn('id', $result->ids() ?: [0])
            ->get()
            ->keyBy(fn (Model $thread): string => (string) $thread->getKey());

        $threads = collect($result->ids())
            ->map(fn (int|string $id): ?Model => $rows->get((string) $id))
            ->filter(fn (?Thread $thread): bool => $thread instanceof Thread && $this->stillVisible($thread, $scope, $viewerId))
            ->values();

        return new LengthAwarePaginator($threads, $result->total, self::PER_PAGE, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
    }

    /**
     * Pending and rejected threads matching the search, for staff who asked to include them. The
     * index holds only approved threads, so these always come from the database.
     *
     * @return Collection<int, Thread>
     */
    public function unapproved(ForumSearchCriteria $criteria): Collection
    {
        $query = Models::class('thread')::query()
            ->with('forum')
            ->whereIn('moderation_status', [ContentModerationStatus::PENDING->value, ContentModerationStatus::REJECTED->value, ContentModerationStatus::REJECTED_FINAL->value])
            ->whereHas('forum', fn (Builder $forum) => $forum->where('is_active', true));

        if ($criteria->forumId !== null) {
            $query->where('forum_id', $criteria->forumId);
        }

        if ($criteria->authorId !== null) {
            $query->where('author_id', $criteria->authorId);
        }

        if ($criteria->minReplies > 0) {
            $query->where('reply_count', '>=', $criteria->minReplies);
        }

        if ($criteria->sinceTimestamp() !== null) {
            $query->where('last_activity_at', '>=', date('Y-m-d H:i:s', $criteria->sinceTimestamp()));
        }

        if ($criteria->query !== '') {
            // Rejected threads are never pruned, so the text match only runs over the most
            // recently active threads that pass the other filters.
            $key = $query->getModel()->getQualifiedKeyName();
            $recent = (clone $query)->reorder()->orderByDesc('last_activity_at')->limit(self::UNAPPROVED_CANDIDATE_LIMIT)->pluck($key);
            $like = $this->like($criteria->query);
            $query->whereIn($key, $recent->all() ?: [0])
                ->where(fn (Builder $match) => $match
                    ->where('title', 'like', $like)
                    ->orWhereHas('posts', fn (Builder $post) => $post->where('body', 'like', $like)));
        }

        /** @var Collection<int, Thread> $found */
        $found = $query->orderByDesc('last_activity_at')->orderByDesc('id')->limit(self::UNAPPROVED_LIMIT)->get();

        return $found;
    }

    /**
     * Posts in one thread that match. `$visiblePosts` is the viewer's own post set for the thread
     * page, so the post numbers and page numbers computed here line up with what they see there.
     *
     * @param  Builder<Post>  $visiblePosts
     * @return LengthAwarePaginator<int, Post>
     */
    public function posts(Builder $visiblePosts, ForumSearchCriteria $criteria, bool $approvedOnly, int $page = 1, int $postsPerPage = 20): LengthAwarePaginator
    {
        $model = $visiblePosts->getModel();
        $col = static fn (string $column): string => $model->qualifyColumn($column);
        $newest = $criteria->postSort === 'newest';
        $order = $newest ? 'desc' : 'asc';

        $matches = (clone $visiblePosts)
            ->with('author')
            ->when($approvedOnly, fn (Builder $query) => $query
                ->where($col('is_deleted'), false)
                ->where($col('moderation_status'), ContentModerationStatus::APPROVED->value))
            ->when($criteria->query !== '', fn (Builder $query) => $query->where($col('body'), 'like', $this->like($criteria->query)))
            ->when($criteria->authorId !== null, fn (Builder $query) => $query->where($col('author_id'), $criteria->authorId))
            ->when($criteria->mineUserId !== null, fn (Builder $query) => $query->where($col('author_id'), $criteria->mineUserId))
            ->when($criteria->sinceTimestamp() !== null, fn (Builder $query) => $query->where($col('created_at'), '>=', date('Y-m-d H:i:s', (int) $criteria->sinceTimestamp())))
            ->orderBy($col('created_at'), $order)
            ->orderBy($col('id'), $order)
            ->paginate(self::PER_PAGE, [$col('*')], 'page', $page);

        $matches->getCollection()->each(function (Post $post) use ($visiblePosts, $postsPerPage, $col): void {
            $position = (clone $visiblePosts)
                ->where(fn (Builder $query) => $query
                    ->where($col('created_at'), '<', $post->created_at)
                    ->orWhere(fn (Builder $same) => $same->where($col('created_at'), $post->created_at)->where($col('id'), '<', $post->id)))
                ->count() + 1;

            $post->setAttribute('search_post_number', $position);
            $post->setAttribute('search_page', (int) ceil($position / max(1, $postsPerPage)));
        });

        return $matches;
    }

    private function canSeeAnything(ForumViewerScope $scope, int|string|null $viewerId): bool
    {
        return $scope->unrestricted || $scope->fullForumIds !== [] || ($scope->ownThreadsForumIds !== [] && $viewerId !== null);
    }

    private function stillVisible(Thread $thread, ForumViewerScope $scope, int|string|null $viewerId): bool
    {
        $forum = $thread->forum;

        if ($thread->moderation_status !== ContentModerationStatus::APPROVED || ! $forum instanceof Forum || ! $forum->is_active) {
            return false;
        }

        return $scope->unrestricted
            || in_array((int) $thread->forum_id, $scope->fullForumIds, true)
            || ($viewerId !== null && (string) $thread->author_id === (string) $viewerId && in_array((int) $thread->forum_id, $scope->ownThreadsForumIds, true));
    }

    private function filter(ForumSearchCriteria $criteria, ForumViewerScope $scope, int|string|null $viewerId): Filter
    {
        $filters = [new Equals('forum_is_active', true)];

        if (! $scope->unrestricted) {
            $visible = [];

            if ($scope->fullForumIds !== []) {
                $visible[] = new In('forum_id', $scope->fullForumIds);
            }

            if ($scope->ownThreadsForumIds !== [] && $viewerId !== null) {
                $visible[] = new All(new In('forum_id', $scope->ownThreadsForumIds), new Equals('author_id', (int) $viewerId));
            }

            $filters[] = new Any(...$visible);
        }

        if ($criteria->forumId !== null) {
            $filters[] = new Equals('forum_id', (int) $criteria->forumId);
        }

        if ($criteria->authorId !== null) {
            $filters[] = $criteria->authorMode === 'posted'
                ? new In('post_author_ids', [(int) $criteria->authorId])
                : new Equals('author_id', (int) $criteria->authorId);
        }

        if ($criteria->mineUserId !== null) {
            $filters[] = new In('post_author_ids', [(int) $criteria->mineUserId]);
        }

        if ($criteria->minReplies > 0) {
            $filters[] = new Range('reply_count', gte: $criteria->minReplies);
        }

        if ($criteria->sinceTimestamp() !== null) {
            $filters[] = new Range('last_post_at_ts', gte: $criteria->sinceTimestamp());
        }

        return new All(...$filters);
    }

    /**
     * @return list<Sort>
     */
    private function sort(ForumSearchCriteria $criteria): array
    {
        return match ($criteria->effectiveSort()) {
            'newest' => [new Sort('created_at_ts', SortDirection::DESC)],
            'replies' => [new Sort('reply_count', SortDirection::DESC)],
            'relevance' => [],
            default => [new Sort('last_post_at_ts', SortDirection::DESC)],
        };
    }

    private function like(string $text): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';
    }
}
