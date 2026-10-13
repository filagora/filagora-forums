<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

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

    /**
     * Holding any of these makes a user staff.
     *
     * @return list<string>
     */
    public static function staff(): array
    {
        return [self::MODERATE, self::VIEW_UNAPPROVED, self::BYPASS_APPROVAL];
    }
}
