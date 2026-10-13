<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Forums\Contracts\StaffPreview;
use Illuminate\Contracts\Auth\Authenticatable;

final class AlwaysStaffPreview implements StaffPreview
{
    public function enabledFor(?Authenticatable $user): bool
    {
        return true;
    }
}
