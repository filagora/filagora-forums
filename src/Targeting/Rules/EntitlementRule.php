<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Forums\Support\KeyField;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\Toggle;

/**
 * Holds (or, negated, does not hold) an entitlement. A guest never does.
 */
final class EntitlementRule implements TargetingRule
{
    public function __construct(private readonly AccessChecker $access) {}

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
            KeyField::make('entitlement')
                ->label(__('filagora-forums::announcements.fields.entitlement')),
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

        $holds = $this->access->allows($viewer->user, $entitlement);

        return ($rule['negate'] ?? false) ? ! $holds : $holds;
    }
}
