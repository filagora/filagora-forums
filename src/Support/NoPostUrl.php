<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Forums\Contracts\PostUrlResolver;
use Filagora\Forums\Models\Post;

final class NoPostUrl implements PostUrlResolver
{
    public function url(Post $post): ?string
    {
        return null;
    }
}
