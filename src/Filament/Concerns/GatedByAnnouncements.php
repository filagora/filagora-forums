<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Concerns;

use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Forums\Support\Abilities;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The announcements screen is for the people who manage them: nothing is allowed unless the user
 * passes the `filagora.forums.announcements` ability (mapped to an entitlement key in
 * `filagora-forums.gates`). It is not a moderator ability.
 */
trait GatedByAnnouncements
{
    use GatedByAbility;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Abilities::ANNOUNCEMENTS;
    }
}
