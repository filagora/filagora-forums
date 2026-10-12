<?php

declare(strict_types=1);

namespace ForumKit\Forums\Services\Concerns;

use ForumKit\Forums\Exceptions\ModerationNotPermitted;
use ForumKit\Forums\Services\ForumAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * For the moderation services: they hold a `ForumAccess` as `$access`.
 *
 * @property ForumAccess $access
 */
trait ChecksModerator
{
    private function authorize(Authenticatable $moderator): void
    {
        if (! $this->access->canModerate($moderator)) {
            throw new ModerationNotPermitted;
        }
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason !== '' ? $reason : null;
    }
}
