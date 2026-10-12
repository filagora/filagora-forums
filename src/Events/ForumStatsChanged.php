<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after forum counters (thread count, post count, last post) change, so a frontend
 * can drop any cache it built from them.
 */
final class ForumStatsChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $forumIds
     */
    public function __construct(public readonly array $forumIds) {}
}
