<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which announcements a user has put away. Two things end an announcement for a user: they
 * dismiss it (when it allows that), or it is shown once and expires on first view (when it is
 * not persistent).
 */
class AnnouncementDismissals
{
    /**
     * A user's own dismissal. Refused (false) unless the announcement exists and is dismissible.
     */
    public function dismiss(Authenticatable $user, int $announcementId): bool
    {
        $dismissible = Models::class('site_notification')::query()->whereKey($announcementId)->value('is_dismissible');

        if ($dismissible === null || ! (bool) $dismissible) {
            return false;
        }

        $this->record($user, $announcementId);

        return true;
    }

    /**
     * Note that a non-persistent announcement was shown, so it is not shown again. Not gated by
     * is_dismissible: this is the announcement ending, not the user acting. Refused (false) for a
     * persistent or missing announcement.
     */
    public function recordFirstView(Authenticatable $user, int $announcementId): bool
    {
        $persistent = Models::class('site_notification')::query()->whereKey($announcementId)->value('is_persistent');

        if ($persistent === null || (bool) $persistent) {
            return false;
        }

        $this->record($user, $announcementId);

        return true;
    }

    /**
     * @return list<int>
     */
    public function dismissedIdsFor(Authenticatable $user): array
    {
        return Models::class('site_notification_dismissal')::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('site_notification_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function record(Authenticatable $user, int $announcementId): void
    {
        Models::class('site_notification_dismissal')::query()->firstOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'site_notification_id' => $announcementId],
            ['dismissed_at' => now()],
        );
    }
}
