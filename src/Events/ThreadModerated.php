<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\Thread;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator acted on a thread: lock, unlock, pin, unpin, move, rename or approve.
 */
final class ThreadModerated
{
    use Dispatchable;

    public function __construct(
        public readonly Thread $thread,
        public readonly Authenticatable $moderator,
        public readonly string $action,
        public readonly ?string $reason,
    ) {}
}
