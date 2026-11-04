<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\Thread;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A thread has just become visible in a forum, and these followers of the forum may read it. Sent in
 * batches, so a busy forum fires it more than once for a thread.
 */
final class ForumSubscribersToNotify
{
    use Dispatchable;

    /**
     * @param  list<Authenticatable>  $recipients
     */
    public function __construct(
        public readonly Thread $thread,
        public readonly array $recipients,
    ) {}
}
