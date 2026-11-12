<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Core\Support\Abilities as CoreAbilities;

/**
 * The Gate abilities that mark a user as forum staff. Each maps to an entitlement key in
 * `filagora-forums.gates`, which a host can point at its own keys.
 */
final class Abilities
{
    /** Moderate content and manage forums. */
    public const MODERATE = 'filagora.forums.moderate';

    /** See content that is not approved. */
    public const VIEW_UNAPPROVED = 'filagora.forums.view_unapproved';

    /** Post without waiting for approval. */
    public const BYPASS_APPROVAL = 'filagora.forums.bypass_approval';

    /** Manage site announcements. Not a staff ability: it does not make a user forum staff. */
    public const ANNOUNCEMENTS = 'filagora.forums.announcements';

    /**
     * Holding any of these makes a user staff.
     *
     * @return list<string>
     */
    public static function staff(): array
    {
        return [self::MODERATE, CoreAbilities::MODERATE_FRONTEND, self::VIEW_UNAPPROVED, self::BYPASS_APPROVAL];
    }

    /**
     * Holding either of these lets a user moderate on the frontend: this package's moderate
     * ability, or core's shared frontend one (`filagora.moderate_frontend`), which opens no
     * dashboard screen. Only `MODERATE` opens the forum screens.
     *
     * @return list<string>
     */
    public static function moderators(): array
    {
        return [self::MODERATE, CoreAbilities::MODERATE_FRONTEND];
    }
}
