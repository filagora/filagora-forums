<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting;

use Filament\Forms\Components\Field;

/**
 * One kind of targeting rule an announcement can carry, such as "has this entitlement". A rule is
 * stored as an array with a `type` and the values its form collects. Register a rule with
 * `TargetingRules::register()`.
 */
interface TargetingRule
{
    /**
     * The `type` stored in the announcement's rule.
     */
    public function type(): string;

    /**
     * What the editor calls the rule.
     */
    public function label(): string;

    /**
     * The form fields for the rule, shown when its type is picked. A field's name is the key it is stored under.
     *
     * @return list<Field>
     */
    public function schema(): array;

    /**
     * Whether the viewer passes the rule. A rule missing the values it needs should pass.
     *
     * @param  array<string, mixed>  $rule
     */
    public function matches(array $rule, Viewer $viewer): bool;
}
