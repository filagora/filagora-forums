<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator acted on a post: delete, restore, approve, unapprove, reject, reject_permanently,
 * move or promote. Listeners notify the author or ban them.
 */
final class PostModerated
{
    use Dispatchable;

    public function __construct(
        public readonly Post $post,
        public readonly Authenticatable $moderator,
        public readonly string $action,
        public readonly ?string $reason,
    ) {}
}
