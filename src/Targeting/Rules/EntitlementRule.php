<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Entitlements\Models\EntitlementDefinition;
use Filagora\Entitlements\Services\AuthorizationService;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

/**
 * Holds (or, negated, does not hold) an entitlement. A guest never does.
 */
final class EntitlementRule implements TargetingRule
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function type(): string
    {
        return 'entitlement';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.entitlement');
    }

    public function schema(): array
    {
        return [
            Select::make('entitlement')
                ->label(__('filagora-forums::announcements.fields.entitlement'))
                ->options(fn (): array => EntitlementDefinition::query()->orderBy('key')->pluck('key', 'key')->all())
                ->searchable(),
            Toggle::make('negate')->label(__('filagora-forums::announcements.fields.negate')),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        $entitlement = trim((string) ($rule['entitlement'] ?? ''));

        if ($entitlement === '') {
            return true;
        }

        $holds = $this->authorization->allows($viewer->user, $entitlement);

        return ($rule['negate'] ?? false) ? ! $holds : $holds;
    }
}
