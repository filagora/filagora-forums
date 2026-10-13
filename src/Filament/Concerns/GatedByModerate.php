<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Concerns;

use Filagora\Entitlements\Filament\Concerns\GatedByAbility;
use Filagora\Forums\Support\Abilities;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every forum screen is for moderators: nothing is allowed unless the user passes the
 * `filagora.forums.moderate` ability (mapped to an entitlement key in `filagora-forums.gates`).
 */
trait GatedByModerate
{
    use GatedByAbility;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Abilities::MODERATE;
    }
}
