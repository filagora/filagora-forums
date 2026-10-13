<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Forums\Contracts\ContentModerationPolicy;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoContentModeration implements ContentModerationPolicy
{
    public function requiresModeration(Authenticatable $user): bool
    {
        return false;
    }

    public function awaitingFirstReview(Authenticatable $user): bool
    {
        return false;
    }
}
