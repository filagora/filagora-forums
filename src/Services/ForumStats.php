<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\ForumStatsChanged;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Support\Models;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each forum's denormalised counters (`thread_count`, `post_count`, `last_post_*`) right.
 *
 * The counters are maintained incrementally, because a forum can hold over a million posts:
 * single-post events adjust the counts by a delta and set or refresh the last post cheaply.
 * `syncForForumIds()` recomputes from scratch, as the safety net for any drift
 * (`filagora:forums:sync-stats`).
 *
 * `post_count` counts approved posts in approved threads and, as in the forum this was
 * extracted from, does not exclude soft-deleted posts.
 */
class ForumStats
{
    /**
     * @param  list<int>  $forumIds
     */
    public function syncForForumIds(array $forumIds): void
    {
        $ids = $this->normalise($forumIds);

        if ($ids === []) {
            return;
        }

        $forumModel = Models::class('forum');
        $threadModel = Models::class('thread');
        $postModel = Models::class('post');
        $threads = (new $threadModel)->getTable();
        $posts = (new $postModel)->getTable();
        $approved = ContentModerationStatus::APPROVED->value;

        $forums = $forumModel::query()->whereIn('id', $ids)->get(['id'])->keyBy('id');

        if ($forums->isEmpty()) {
            return;
        }

        $threadCounts = $threadModel::query()
            ->selectRaw('forum_id, COUNT(*) as aggregate')
            ->whereIn('forum_id', $ids)
            ->where('moderation_status', $approved)
            ->groupBy('forum_id')
            ->pluck('aggregate', 'forum_id')
            ->mapWithKeys(fn (mixed $count, mixed $forumId): array => [(int) $forumId => (int) $count])
            ->all();

        $postCounts = $postModel::query()
            ->join($threads, "{$threads}.id", '=', "{$posts}.thread_id")
            ->whereIn("{$threads}.forum_id", $ids)
            ->where("{$threads}.moderation_status", $approved)
            ->where("{$posts}.moderation_status", $approved)
            ->selectRaw("{$threads}.forum_id as forum_id, COUNT(*) as aggregate")
            ->groupBy("{$threads}.forum_id")
            ->pluck('aggregate', 'forum_id')
            ->mapWithKeys(fn (mixed $count, mixed $forumId): array => [(int) $forumId => (int) $count])
            ->all();

        foreach ($forums as $forumId => $forum) {
            $forumId = (int) $forumId;
            $last = $this->latestApprovedPostForForum($forumId);

            $forum->forceFill([
                'thread_count' => $threadCounts[$forumId] ?? 0,
                'post_count' => $postCounts[$forumId] ?? 0,
                'last_post_id' => $last !== null ? (int) $last->post_id : null,
                'last_post_thread_id' => $last !== null ? (int) $last->thread_id : null,
                'last_post_author_id' => $last !== null ? (int) $last->author_id : null,
                'last_post_at' => $last?->created_at,
            ])->save();
        }

        ForumStatsChanged::dispatch($forums->keys()->map(fn (mixed $id): int => (int) $id)->all());
    }

    public function syncForForumId(int $forumId): void
    {
        $this->syncForForumIds([$forumId]);
    }

    /**
     * Adjust a forum's post count by a known delta, without recounting.
     */
    public function applyPostCountDelta(int $forumId, int $delta): void
    {
        if ($forumId <= 0 || $delta === 0) {
            return;
        }

        $forum = Models::class('forum');

        if ($delta > 0) {
            $forum::query()->whereKey($forumId)->increment('post_count', $delta);
        } else {
            // A plain decrement, portable across databases; any drift is corrected by the full
            // recompute, the safety net every counter cache relies on.
            $forum::query()->whereKey($forumId)->decrement('post_count', abs($delta));
        }

        ForumStatsChanged::dispatch([$forumId]);
    }

    /**
     * Make a newly counted post the forum's last post. Post ids only grow, so a newly counted
     * post is always the latest and no lookup is needed.
     */
    public function setLastPostFromPost(int $forumId, Post $post): void
    {
        if ($forumId <= 0) {
            return;
        }

        $forum = Models::class('forum');

        $forum::query()->whereKey($forumId)->update([
            'last_post_id' => $post->id,
            'last_post_thread_id' => $post->thread_id,
            'last_post_author_id' => $post->author_id,
            'last_post_at' => $post->created_at,
        ]);

        ForumStatsChanged::dispatch([$forumId]);
    }

    /**
     * Look the forum's last post up again, for when the post that was last has left the counted
     * set (moderated away, moved or deleted).
     */
    public function refreshLastPostForForumId(int $forumId): void
    {
        if ($forumId <= 0) {
            return;
        }

        $last = $this->latestApprovedPostForForum($forumId);
        $forum = Models::class('forum');

        $forum::query()->whereKey($forumId)->update([
            'last_post_id' => $last?->post_id,
            'last_post_thread_id' => $last?->thread_id,
            'last_post_author_id' => $last?->author_id,
            'last_post_at' => $last?->created_at,
        ]);

        ForumStatsChanged::dispatch([$forumId]);
    }

    /**
     * Count a newly approved post (and, for a new thread, the thread) in its forum.
     */
    public function incrementForApprovedPost(Forum $forum, Post $post, bool $incrementThreadCount): void
    {
        $updates = [
            'post_count' => DB::raw('COALESCE(post_count, 0) + 1'),
            'last_post_id' => (int) $post->id,
            'last_post_thread_id' => (int) $post->thread_id,
            'last_post_author_id' => (int) $post->author_id,
            'last_post_at' => $post->created_at,
            'updated_at' => now(),
        ];

        if ($incrementThreadCount) {
            $updates['thread_count'] = DB::raw('COALESCE(thread_count, 0) + 1');
        }

        $model = Models::class('forum');

        $model::query()->whereKey($forum->getKey())->update($updates);

        ForumStatsChanged::dispatch([(int) $forum->getKey()]);
    }

    /**
     * The latest approved post in a forum, without scanning the posts table.
     *
     * Walking posts backwards looking for one in this forum degrades badly for a forum whose most
     * recent activity is far behind the site's most recent post (potentially tens of seconds on a
     * large forum). Instead this leans on `threads.last_post_at`, which is only ever set from an
     * approved post: the approved thread with the greatest `last_post_at` holds the forum's latest
     * approved post, found through the `(forum_id, moderation_status, last_post_at)` index, and
     * the second query reads that one thread's latest approved post.
     */
    private function latestApprovedPostForForum(int $forumId): ?object
    {
        $threadModel = Models::class('thread');
        $postModel = Models::class('post');
        $approved = ContentModerationStatus::APPROVED->value;

        $threadId = $threadModel::query()
            ->where('forum_id', $forumId)
            ->where('moderation_status', $approved)
            ->orderByDesc('last_post_at')
            ->value('id');

        if ($threadId === null) {
            return null;
        }

        return $postModel::query()
            ->where('thread_id', (int) $threadId)
            ->where('moderation_status', $approved)
            ->select(['id as post_id', 'thread_id', 'author_id', 'created_at'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  list<int>  $forumIds
     * @return list<int>
     */
    private function normalise(array $forumIds): array
    {
        return array_values(array_filter(
            array_unique(array_map('intval', $forumIds)),
            static fn (int $id): bool => $id > 0,
        ));
    }
}
