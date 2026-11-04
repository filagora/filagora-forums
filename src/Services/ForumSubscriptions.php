<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Core\Support\UserModel;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\ForumSubscribersToNotify;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumSubscription;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Following a whole forum. `announce()` tells the followers a thread has appeared in it: those who
 * may still read it, leaving out its author and anyone already following the thread. Reading is
 * decided by `ForumAccess`, so someone whose access has lapsed stops hearing about a private forum.
 */
class ForumSubscriptions
{
    private const CHUNK_SIZE = 200;

    public function __construct(private readonly ForumAccess $access) {}

    public function subscribe(Authenticatable $user, Forum $forum): void
    {
        $this->subscriptions()->firstOrCreate(['forum_id' => $forum->getKey(), 'user_id' => $user->getAuthIdentifier()]);
    }

    public function unsubscribe(Authenticatable $user, Forum $forum): void
    {
        $this->subscriptions()->where('forum_id', $forum->getKey())->where('user_id', $user->getAuthIdentifier())->delete();
    }

    public function isSubscribed(?Authenticatable $user, Forum $forum): bool
    {
        return $user !== null && $this->subscriptions()->where('forum_id', $forum->getKey())->where('user_id', $user->getAuthIdentifier())->exists();
    }

    /**
     * Which of the forums a person follows, for a page listing many.
     *
     * @param  iterable<int>  $forumIds
     * @return list<int>
     */
    public function followedAmong(?Authenticatable $user, iterable $forumIds): array
    {
        $ids = collect($forumIds)->map(fn ($id): int => (int) $id)->values()->all();

        if ($user === null || $ids === []) {
            return [];
        }

        return array_values(array_map('intval', $this->subscriptions()->where('user_id', $user->getAuthIdentifier())->whereIn('forum_id', $ids)->pluck('forum_id')->all()));
    }

    /**
     * The forums a person follows, by name.
     *
     * @return Collection<int, Forum>
     */
    public function forumsFollowedBy(Authenticatable $user): Collection
    {
        /** @var Collection<int, Forum> */
        return Models::class('forum')::query()
            ->whereIn('id', $this->subscriptions()->where('user_id', $user->getAuthIdentifier())->select('forum_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Hand the followers of a thread's forum to `ForumSubscribersToNotify`, in batches. Does nothing
     * for a thread that is not approved. Returns how many were told.
     */
    public function announce(Thread $thread): int
    {
        $forum = $thread->forum;

        if (! $forum instanceof Forum || $thread->moderation_status !== ContentModerationStatus::APPROVED) {
            return 0;
        }

        $told = 0;
        $users = UserModel::class();
        $key = (new $users)->getKeyName();

        $users::query()
            ->whereIn($key, $this->subscriptions()->where('forum_id', $forum->getKey())->select('user_id'))
            ->where($key, '!=', $thread->author_id)
            ->whereNotIn($key, Models::class('thread_subscription')::query()->where('thread_id', $thread->getKey())->select('user_id'))
            ->chunkById(self::CHUNK_SIZE, function ($followers) use ($thread, $forum, &$told): void {
                $recipients = [];

                foreach ($followers as $follower) {
                    if ($follower instanceof Authenticatable && $this->access->canViewForum($forum, $follower) && $this->access->canAccessThread($thread, $follower)) {
                        $recipients[] = $follower;
                    }
                }

                if ($recipients !== []) {
                    ForumSubscribersToNotify::dispatch($thread, $recipients);
                    $told += count($recipients);
                }
            });

        return $told;
    }

    /**
     * @return Builder<ForumSubscription>
     */
    private function subscriptions(): Builder
    {
        /** @var Builder<ForumSubscription> */
        return Models::class('forum_subscription')::query();
    }
}
