<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * People newly mentioned in a post (those who could see the thread and had not been told before,
 * up to the cap per post). By default each is emailed; a host with more channels turns that off
 * and listens instead.
 */
final class UsersMentioned
{
    use Dispatchable;

    /**
     * @param  list<Authenticatable>  $users
     */
    public function __construct(public readonly Post $post, public readonly array $users) {}
}
