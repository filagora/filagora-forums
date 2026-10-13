<?php

declare(strict_types=1);

namespace Filagora\Forums\Observers;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ForumStats;
use Filagora\Forums\Support\Models;
use Filagora\Forums\Support\StatsSyncScope;

/**
 * Keeps forum counters right as threads and posts change.
 *
 * Thread-level events (new, approved, rejected or moved threads) are rare moderation actions, so
 * they trigger a full recompute of the affected forums. Post-level events run on every reply
 * and edit, so they adjust the counters by a delta instead: a post only counts when it and its
 * thread are both approved.
 */
final class ForumStatsObserver
{
    public function __construct(private readonly ForumStats $stats) {}

    /**
     * created and updated, not saved plus wasRecentlyCreated: that flag stays true on the model
     * for every later save, which would send a later edit back down the create path.
     */
    public function created(Thread|Post $model): void
    {
        $model instanceof Thread ? $this->syncThread($model) : $this->handlePostCreated($model);
    }

    public function updated(Thread|Post $model): void
    {
        $model instanceof Thread ? $this->syncThread($model) : $this->handlePostUpdated($model);
    }

    public function deleted(Thread|Post $model): void
    {
        $model instanceof Thread ? $this->syncThread($model) : $this->handlePostDeleted($model);
    }

    private function syncThread(Thread $thread): void
    {
        if (StatsSyncScope::suppressesThreadSync()) {
            return;
        }

        $forumIds = [(int) $thread->forum_id];
        $originalForumId = (int) ($thread->getOriginal('forum_id') ?? 0);

        if ($originalForumId > 0) {
            $forumIds[] = $originalForumId;
        }

        $this->stats->syncForForumIds(array_values(array_unique(array_filter($forumIds, fn (int $id): bool => $id > 0))));
    }

    private function handlePostCreated(Post $post): void
    {
        if (StatsSyncScope::suppressesPostSync()) {
            return;
        }

        // A freshly inserted row does not carry database defaults unless they were passed, so a
        // caller relying on the default moderation status would otherwise read null here.
        $post->refresh();

        $state = $this->threadState((int) $post->thread_id);

        if ($state !== null && $state['approved'] && $this->isApproved($post)) {
            $this->stats->applyPostCountDelta($state['forum_id'], 1);
            $this->stats->setLastPostFromPost($state['forum_id'], $post);
        }
    }

    private function handlePostUpdated(Post $post): void
    {
        if (StatsSyncScope::suppressesPostSync()) {
            return;
        }

        $moderationChanged = $post->wasChanged('moderation_status');
        $threadChanged = $post->wasChanged('thread_id');

        // A plain body edit changes nothing the counters depend on.
        if (! $moderationChanged && ! $threadChanged) {
            return;
        }

        $originalThreadId = $threadChanged ? (int) $post->getOriginal('thread_id') : (int) $post->thread_id;
        $originalState = $this->threadState($originalThreadId);
        $currentState = $threadChanged ? $this->threadState((int) $post->thread_id) : $originalState;

        $wasApproved = $moderationChanged
            ? $post->getOriginal('moderation_status') === ContentModerationStatus::APPROVED
            : $this->isApproved($post);
        $isApproved = $this->isApproved($post);

        $wasCounted = $originalState !== null && $originalState['approved'] && $wasApproved;
        $isCounted = $currentState !== null && $currentState['approved'] && $isApproved;

        $originalForumId = $originalState['forum_id'] ?? null;
        $currentForumId = $currentState['forum_id'] ?? null;

        if ($originalForumId !== null && $originalForumId === $currentForumId) {
            if ($wasCounted && ! $isCounted) {
                $this->stats->applyPostCountDelta($originalForumId, -1);
                $this->stats->refreshLastPostForForumId($originalForumId);
            } elseif (! $wasCounted && $isCounted) {
                $this->stats->applyPostCountDelta($originalForumId, 1);
                $this->stats->setLastPostFromPost($originalForumId, $post);
            }

            // Counted before and after in the same forum means only thread_id moved within the
            // forum; the post's id, author and time are unchanged, so the forum's last post cannot
            // have changed either.
            return;
        }

        // Counted implies the thread, and so its forum, was found.
        if ($wasCounted) {
            $this->stats->applyPostCountDelta((int) $originalForumId, -1);
            $this->stats->refreshLastPostForForumId((int) $originalForumId);
        }

        if ($isCounted) {
            $this->stats->applyPostCountDelta((int) $currentForumId, 1);
            $this->stats->setLastPostFromPost((int) $currentForumId, $post);
        }
    }

    private function handlePostDeleted(Post $post): void
    {
        if (StatsSyncScope::suppressesPostSync()) {
            return;
        }

        $state = $this->threadState((int) $post->thread_id);

        if ($state !== null && $state['approved'] && $this->isApproved($post)) {
            $this->stats->applyPostCountDelta($state['forum_id'], -1);
            $this->stats->refreshLastPostForForumId($state['forum_id']);
        }
    }

    /**
     * @return array{forum_id: int, approved: bool}|null
     */
    private function threadState(int $threadId): ?array
    {
        if ($threadId <= 0) {
            return null;
        }

        $model = Models::class('thread');
        $thread = $model::query()->select(['id', 'forum_id', 'moderation_status'])->find($threadId);

        if (! $thread instanceof Thread) {
            return null;
        }

        return [
            'forum_id' => (int) $thread->forum_id,
            'approved' => $thread->moderation_status === ContentModerationStatus::APPROVED,
        ];
    }

    private function isApproved(Post $post): bool
    {
        return $post->moderation_status === ContentModerationStatus::APPROVED;
    }
}
