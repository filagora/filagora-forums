<?php

declare(strict_types=1);

namespace ForumKit\Forums\ValueObjects;

use ForumKit\Forums\Models\Post;

final readonly class CreatedReply
{
    /**
     * @param  bool  $requiresApproval  The reply waits for a moderator.
     * @param  bool  $duplicate  An identical reply was posted moments ago; this is that one, not a new one.
     */
    public function __construct(
        public Post $post,
        public bool $requiresApproval,
        public bool $duplicate = false,
    ) {}
}
