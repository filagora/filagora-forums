<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Core\Support\UserModel;
use Filagora\Forums\Contracts\SubscriptionPreferences;
use Filagora\Forums\Enums\DeliveryMode;
use Filagora\Forums\Events\SubscribersToNotify;
use Filagora\Forums\Events\ThreadDigestReady;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadSubscription;
use Filagora\Forums\Models\ThreadSubscriptionDigestItem;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Following threads. People follow a thread by choice or by posting in it, and unfollow whenever
 * they like. A new approved post is turned into updates for the followers: those who want it at
 * once are handed to the `SubscribersToNotify` event, those who want a daily or weekly digest get a
 * row in the digest queue, and those who turned updates off get nothing. `sendDigests()` empties
 * the queue into `ThreadDigestReady` events.
 */
class Subscriptions
{
    public function __construct(private readonly SubscriptionPreferences $preferences) {}

    public function subscribe(Authenticatable $user, Thread $thread): void
    {
        $this->subscriptions()->firstOrCreate(['thread_id' => $thread->getKey(), 'user_id' => $user->getAuthIdentifier()]);
    }

    public function unsubscribe(Authenticatable $user, Thread $thread): void
    {
        $this->subscriptions()->where('thread_id', $thread->getKey())->where('user_id', $user->getAuthIdentifier())->delete();
    }

    public function isSubscribed(?Authenticatable $user, Thread $thread): bool
    {
        return $user !== null && $this->subscriptions()->where('thread_id', $thread->getKey())->where('user_id', $user->getAuthIdentifier())->exists();
    }

    /**
     * Which of the threads a person follows, for a page listing many.
     *
     * @param  iterable<int>  $threadIds
     * @return list<int>
     */
    public function followedAmong(?Authenticatable $user, iterable $threadIds): array
    {
        $ids = collect($threadIds)->map(fn ($id): int => (int) $id)->values()->all();

        if ($user === null || $ids === []) {
            return [];
        }

        return array_values(array_map('intval', $this->subscriptions()->where('user_id', $user->getAuthIdentifier())->whereIn('thread_id', $ids)->pluck('thread_id')->all()));
    }

    /**
     * Whether posting should follow the thread: the person's say-so on this post if they gave one,
     * else their standing preference.
     */
    public function shouldFollowOnPost(Authenticatable $user, ?bool $requested = null): bool
    {
        return $requested ?? $this->preferences->autoSubscribeOnPost($user);
    }

    /**
     * Turn a new post into updates for the thread's followers (not its author). Pending posts do
     * nothing: call this once they are approved.
     */
    public function announce(Post $post): void
    {
        $thread = $post->thread;
        assert($thread instanceof Thread);

        $instant = [];
        $digest = [];

        foreach ($this->followers($thread, (int) $post->author_id) as $follower) {
            $mode = $this->preferences->deliveryMode($follower);

            if ($mode === DeliveryMode::INSTANT) {
                $instant[] = $follower;
            } elseif ($mode->isDigest()) {
                $digest[] = (int) $follower->getAuthIdentifier();
            }
        }

        if ($digest !== []) {
            $now = now();

            $this->digestItems()->upsert(
                array_map(fn (int $userId): array => ['user_id' => $userId, 'thread_id' => $thread->getKey(), 'post_id' => $post->getKey(), 'created_at' => $now, 'updated_at' => $now], $digest),
                ['user_id', 'post_id'],
                ['thread_id', 'updated_at'],
            );
        }

        if ($instant !== []) {
            SubscribersToNotify::dispatch($post, $thread, $instant);
        }
    }

    /**
     * Send the digests that are due for a frequency: each person with unsent items whose delivery
     * mode is that frequency gets one `ThreadDigestReady` and their items are marked sent. With
     * `$dryRun`, nothing is dispatched or marked.
     *
     * @return array{users: int, items: int}
     */
    public function sendDigests(DeliveryMode $frequency, bool $dryRun = false): array
    {
        $users = 0;
        $items = 0;

        $userIds = $this->digestItems()->whereNull('sent_at')->distinct()->orderBy('user_id')->pluck('user_id');

        foreach ($userIds as $userId) {
            $user = UserModel::class()::query()->find($userId);

            if (! $user instanceof Authenticatable || $this->preferences->deliveryMode($user) !== $frequency) {
                continue;
            }

            /** @var list<ThreadSubscriptionDigestItem> $due */
            $due = $this->digestItems()->where('user_id', $userId)->whereNull('sent_at')->with(['thread', 'post'])->orderBy('created_at')->orderBy('id')->get()->all();

            if ($due === []) {
                continue;
            }

            if (! $dryRun) {
                ThreadDigestReady::dispatch($user, $frequency, $due);
                $this->digestItems()->whereIn('id', array_map(fn (ThreadSubscriptionDigestItem $item) => $item->getKey(), $due))->update(['sent_at' => now()]);
            }

            $users++;
            $items += count($due);
        }

        return ['users' => $users, 'items' => $items];
    }

    /**
     * @return list<Authenticatable>
     */
    private function followers(Thread $thread, int $exceptUserId): array
    {
        $userModel = UserModel::class();

        /** @var list<Authenticatable> */
        return $userModel::query()
            ->whereIn('id', $this->subscriptions()->where('thread_id', $thread->getKey())->where('user_id', '!=', $exceptUserId)->select('user_id'))
            ->get()
            ->all();
    }

    /**
     * @return Builder<ThreadSubscription>
     */
    private function subscriptions(): Builder
    {
        /** @var Builder<ThreadSubscription> */
        return Models::class('thread_subscription')::query();
    }

    /**
     * @return Builder<ThreadSubscriptionDigestItem>
     */
    private function digestItems(): Builder
    {
        /** @var Builder<ThreadSubscriptionDigestItem> */
        return Models::class('thread_subscription_digest_item')::query();
    }
}
