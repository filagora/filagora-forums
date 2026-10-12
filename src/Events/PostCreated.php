<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A post was created: a reply, or the first post of a new thread (`$isFirstPost`). Add-ons hook
 * in here for mentions, notifications and attachments; skip pending ones until they are approved.
 */
final class PostCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Post $post,
        public readonly Thread $thread,
        public readonly bool $requiresApproval,
        public readonly bool $isFirstPost,
    ) {}
}
