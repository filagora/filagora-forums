<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Concerns;

use ForumKit\Entitlements\Filament\Concerns\GatedByAbility;
use ForumKit\Forums\Support\Abilities;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every forum screen is for moderators: nothing is allowed unless the user passes the
 * `forumkit.forums.moderate` ability (mapped to an entitlement key in `forumkit-forums.gates`).
 */
trait GatedByModerate
{
    use GatedByAbility;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Abilities::MODERATE;
    }
}
