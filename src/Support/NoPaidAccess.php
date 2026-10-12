<?php

declare(strict_types=1);

namespace ForumKit\Forums\Support;

use ForumKit\Forums\Contracts\PaidAccessCheck;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoPaidAccess implements PaidAccessCheck
{
    public function hasPaidAccess(Authenticatable $user): bool
    {
        return false;
    }
}
