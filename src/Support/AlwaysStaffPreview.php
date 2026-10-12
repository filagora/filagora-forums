<?php

declare(strict_types=1);

namespace ForumKit\Forums\Support;

use ForumKit\Forums\Contracts\StaffPreview;
use Illuminate\Contracts\Auth\Authenticatable;

final class AlwaysStaffPreview implements StaffPreview
{
    public function enabledFor(?Authenticatable $user): bool
    {
        return true;
    }
}
