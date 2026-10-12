<?php

declare(strict_types=1);

namespace ForumKit\Forums\ValueObjects;

use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;

final readonly class CreatedThread
{
    /**
     * @param  bool  $requiresApproval  The thread and its first post wait for a moderator.
     * @param  bool  $duplicate  An identical thread was started moments ago; this is that one, not a new one.
     */
    public function __construct(
        public Thread $thread,
        public Post $post,
        public bool $requiresApproval,
        public bool $duplicate = false,
    ) {}
}
