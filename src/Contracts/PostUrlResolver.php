<?php

declare(strict_types=1);

namespace Filagora\Forums\Contracts;

use Filagora\Forums\Models\Post;

/**
 * The address of one post on the host's thread page (the page that holds it, anchored at the post).
 * The dashboard's "Read" action on a post report opens it in a new tab when this answers; a host with
 * paged threads binds its own. The default answers nothing, and the action shows the post's text in a
 * modal instead.
 */
interface PostUrlResolver
{
    public function url(Post $post): ?string;
}
