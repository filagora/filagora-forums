<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Forums\Contracts\PaidAccessCheck;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoPaidAccess implements PaidAccessCheck
{
    public function hasPaidAccess(Authenticatable $user): bool
    {
        return false;
    }
}
