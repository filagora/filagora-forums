<?php

declare(strict_types=1);

namespace Filagora\Forums\Jobs;

use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ForumSubscriptions;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells a forum's followers about a thread, off the request: a busy forum can have many.
 */
final class AnnounceThreadToForumSubscribers implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int|string $threadId) {}

    public function handle(ForumSubscriptions $subscriptions): void
    {
        $thread = Models::class('thread')::query()->find($this->threadId);

        if ($thread instanceof Thread) {
            $subscriptions->announce($thread);
        }
    }
}
