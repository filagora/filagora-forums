<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Entitlements\Models\EntitlementDefinition;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * The form field for an access key (the key a forum or an announcement rule asks the access
 * checker about). With `filagora-entitlements` installed it picks from the defined entitlements;
 * without it the key is typed, because what a key means is then up to the host's checker.
 */
final class KeyField
{
    public static function make(string $name): Field
    {
        if (! app(ModuleRegistry::class)->has('entitlements') || ! class_exists(EntitlementDefinition::class)) {
            return TextInput::make($name);
        }

        return Select::make($name)
            ->options(fn (): array => EntitlementDefinition::selectorOptions())
            ->searchable()
            ->preload()
            ->native(false);
    }
}
