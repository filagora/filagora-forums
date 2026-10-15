<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new approved post in a thread, and the followers who want to hear about it at once (everyone
 * except the author and anyone whose delivery mode is a digest or off). By default each is emailed;
 * a host with more channels (a bell, browser push) turns that off and listens instead.
 */
final class SubscribersToNotify
{
    use Dispatchable;

    /**
     * @param  list<Authenticatable>  $recipients
     */
    public function __construct(
        public readonly Post $post,
        public readonly Thread $thread,
        public readonly array $recipients,
    ) {}
}
