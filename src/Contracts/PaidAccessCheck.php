<?php

declare(strict_types=1);

namespace Filagora\Forums\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether a user has "paid" access, for forums whose visibility is `paid_only` or
 * `paid_or_author` and that name no entitlement. The package has no notion of payment:
 * a memberships add-on or the host binds this. The default grants nobody.
 *
 * When a forum names a `required_entitlement_scope`, holding that entitlement is what counts
 * and this check is not asked.
 */
interface PaidAccessCheck
{
    public function hasPaidAccess(Authenticatable $user): bool;
}
