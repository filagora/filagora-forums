<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use ForumKit\Forums\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

final class PostEdited
{
    use Dispatchable;

    public function __construct(
        public readonly Post $post,
        public readonly Authenticatable $editor,
    ) {}
}
