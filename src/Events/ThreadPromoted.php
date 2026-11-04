<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\Thread;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator made a new thread out of one or more replies.
 */
final class ThreadPromoted
{
    use Dispatchable;

    public function __construct(
        public readonly Thread $thread,
        public readonly Authenticatable $moderator,
    ) {}
}
