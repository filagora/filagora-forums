<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Enums\DeliveryMode;
use Filagora\Forums\Models\ThreadSubscriptionDigestItem;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person's digest is due: the posts that came in since the last one, oldest first (each with its
 * thread and post loaded). By default it is emailed; the items are marked sent once this is dispatched.
 */
final class ThreadDigestReady
{
    use Dispatchable;

    /**
     * @param  list<ThreadSubscriptionDigestItem>  $items
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly DeliveryMode $frequency,
        public readonly array $items,
    ) {}
}
