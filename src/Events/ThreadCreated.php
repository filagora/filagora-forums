<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new thread and its first post were created. A subscriptions add-on follows `$subscribeAuthor`.
 */
final class ThreadCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Thread $thread,
        public readonly Post $post,
        public readonly bool $requiresApproval,
        public readonly bool $subscribeAuthor,
    ) {}
}
