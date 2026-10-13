<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Events\PostModerated;
use Filagora\Forums\Events\ThreadDeleted;
use Filagora\Forums\Exceptions\InvalidModeration;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\Concerns\ChecksModerator;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Moving posts between threads and promoting posts into a new thread, one at a time or in a
 * batch. A source thread left with no posts is removed, and the audit rows and reports of the
 * posts that left it follow them to their new thread.
 *
 * Each post gets a `move` or `promote` log row and a `PostModerated` event. There is no undo
 * here: that is a frontend concern (it needs somewhere to keep a short-lived token).
 */
class PostRelocation
{
    use ChecksModerator;

    public const MAX_BATCH = 200;

    public function __construct(
        private readonly ForumAccess $access,
        private readonly ThreadCreation $threads,
        private readonly ThreadStats $threadStats,
    ) {}

    /**
     * @throws InvalidModeration When the post is already in that thread.
     */
    public function move(Authenticatable $moderator, Post $post, Thread $destination, ?string $reason = null): void
    {
        if ((int) $post->thread_id === (int) $destination->getKey()) {
            throw new InvalidModeration('The post is already in that thread.');
        }

        $this->movePosts($moderator, [$post], $destination, $reason);
    }

    /**
     * Move posts into an existing thread. Posts already there are left alone.
     *
     * @param  iterable<Post>  $posts
     */
    public function movePosts(Authenticatable $moderator, iterable $posts, Thread $destination, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $posts = $this->batch($posts);
        $reason = $this->cleanReason($reason);

        $outcome = DB::transaction(function () use ($moderator, $posts, $destination, $reason): array {
            $moved = [];

            foreach ($posts as $post) {
                if ((int) $post->thread_id === (int) $destination->getKey()) {
                    continue;
                }

                $sourceId = (int) $post->thread_id;
                $post->thread_id = $destination->getKey();
                $post->save();
                $this->log($moderator, $sourceId, $post, 'move', $reason);
                $moved[] = [$post, $sourceId, 'move'];
            }

            return [$moved, $this->settle($moved, (int) $destination->getKey())];
        });

        $this->announce($moderator, $outcome[0], $outcome[1], $reason);
    }

    /**
     * Start a new thread in `$destination` from one post.
     */
    public function promote(Authenticatable $moderator, Post $post, Forum $destination, string $title, ?string $reason = null): Thread
    {
        return $this->promotePosts($moderator, [$post], $destination, $title, $reason);
    }

    /**
     * Start a new thread from several posts: the earliest becomes its opening post, the rest are
     * moved in after it. The new thread takes the opening post's moderation state and author.
     *
     * @param  iterable<Post>  $posts
     */
    public function promotePosts(Authenticatable $moderator, iterable $posts, Forum $destination, string $title, ?string $reason = null): Thread
    {
        $this->authorize($moderator);
        $title = trim($title);

        if ($title === '') {
            throw new InvalidModeration('A thread needs a title.');
        }

        $posts = $this->batch($posts);
        usort($posts, static fn (Post $a, Post $b): int => [$a->created_at, $a->getKey()] <=> [$b->created_at, $b->getKey()]);
        $reason = $this->cleanReason($reason);
        $threadModel = Models::class('thread');

        [$thread, $moved, $removed] = DB::transaction(function () use ($moderator, $posts, $destination, $title, $reason, $threadModel): array {
            $first = $posts[0];

            /** @var Thread $thread */
            $thread = $threadModel::query()->create([
                'forum_id' => $destination->getKey(),
                'author_id' => $first->author_id,
                'title' => $title,
                'slug' => $this->threads->uniqueSlug($title),
                'moderation_status' => $first->moderation_status,
                'is_pinned' => false,
                'is_locked' => false,
                'reply_count' => 0,
                'approved_by_id' => $first->approved_by_id,
                'approved_at' => $first->approved_at,
            ]);

            $moved = [];

            foreach ($posts as $index => $post) {
                $sourceId = (int) $post->thread_id;
                $post->thread_id = $thread->getKey();
                $post->save();
                $action = $index === 0 ? 'promote' : 'move';
                $this->log($moderator, $sourceId, $post, $action, $reason);
                $moved[] = [$post, $sourceId, $action];
            }

            $removed = $this->settle($moved, (int) $thread->getKey());

            return [$thread->refresh(), $moved, $removed];
        });

        $this->announce($moderator, $moved, $removed, $reason);

        return $thread;
    }

    /**
     * @param  iterable<Post>  $posts
     * @return list<Post>
     */
    private function batch(iterable $posts): array
    {
        $list = [];

        foreach ($posts as $post) {
            $list[] = $post;
        }

        if ($list === []) {
            throw new InvalidModeration('Choose at least one post.');
        }

        if (count($list) > self::MAX_BATCH) {
            throw new InvalidModeration('Choose at most '.self::MAX_BATCH.' posts at a time.');
        }

        return $list;
    }

    private function log(Authenticatable $moderator, int $sourceThreadId, Post $post, string $action, ?string $reason): void
    {
        PostModerationLog::query()->create([
            'thread_id' => $sourceThreadId,
            'post_id' => $post->getKey(),
            'moderator_id' => $moderator->getAuthIdentifier(),
            'action' => $action,
            'reason' => $reason,
        ]);
    }

    /**
     * Remove source threads the move emptied and re-derive every thread touched.
     *
     * @param  list<array{0: Post, 1: int, 2: string}>  $moved  Each post, the thread it came from and the action taken.
     * @return array<int, array{id: int, forum_id: int, author_id: int}> The threads removed.
     */
    private function settle(array $moved, int $destinationId): array
    {
        $sourceIds = array_values(array_unique(array_map(static fn (array $entry): int => $entry[1], $moved)));
        $removed = [];

        foreach ($sourceIds as $sourceId) {
            if ($sourceId !== $destinationId) {
                $this->removeIfEmpty($sourceId, $destinationId, $removed);
            }
        }

        $this->threadStats->syncForThreadIds(array_values(array_diff([...$sourceIds, $destinationId], array_keys($removed))));

        return $removed;
    }

    /**
     * @param  list<array{0: Post, 1: int, 2: string}>  $moved
     * @param  array<int, array{id: int, forum_id: int, author_id: int}>  $removed
     */
    private function announce(Authenticatable $moderator, array $moved, array $removed, ?string $reason): void
    {
        foreach ($removed as $info) {
            ThreadDeleted::dispatch($info['id'], $info['forum_id'], $info['author_id'], [], $moderator, $reason);
        }

        foreach ($moved as [$post, , $action]) {
            PostModerated::dispatch($post, $moderator, $action, $reason);
        }
    }

    /**
     * @param  array<int, array{id: int, forum_id: int, author_id: int}>  $removed
     */
    private function removeIfEmpty(int $threadId, int $destinationId, array &$removed): void
    {
        $postModel = Models::class('post');
        $threadModel = Models::class('thread');

        if ($postModel::query()->where('thread_id', $threadId)->exists()) {
            return;
        }

        /** @var Thread|null $thread */
        $thread = $threadModel::query()->find($threadId);

        if ($thread === null) {
            return;
        }

        // The thread has no posts, so every log and report on it belongs to a post that now lives
        // elsewhere: carry them across before the cascade takes them.
        PostModerationLog::query()->where('thread_id', $threadId)->update(['thread_id' => $destinationId]);
        PostReport::query()->where('thread_id', $threadId)->update(['thread_id' => $destinationId]);

        $removed[$threadId] = [
            'id' => $threadId,
            'forum_id' => (int) $thread->forum_id,
            'author_id' => (int) $thread->author_id,
        ];
        $thread->delete();
    }
}
