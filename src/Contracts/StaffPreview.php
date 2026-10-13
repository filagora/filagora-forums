<?php

declare(strict_types=1);

namespace Filagora\Forums\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Lets a frontend switch staff privileges off to see the forum as an ordinary member does.
 * A user who holds a staff entitlement only acts as staff while this returns true. The
 * default is always on; a host with a "view as member" toggle binds its own.
 */
interface StaffPreview
{
    public function enabledFor(?Authenticatable $user): bool;
}
