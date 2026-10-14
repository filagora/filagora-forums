<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting;

/**
 * The kinds of targeting rule known to the application, and the evaluation of an announcement's
 * rules. All rules must pass; a rule that is not an array is skipped and a rule of an unknown
 * type passes, so an announcement never disappears because a rule type was removed.
 */
final class TargetingRules
{
    /** @var array<string, TargetingRule> */
    private array $rules = [];

    public function register(TargetingRule $rule): void
    {
        $this->rules[$rule->type()] = $rule;
    }

    public function has(string $type): bool
    {
        return isset($this->rules[$type]);
    }

    /**
     * @return array<string, TargetingRule>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, string> type => label, for the rule picker.
     */
    public function options(): array
    {
        return array_map(static fn (TargetingRule $rule): string => $rule->label(), $this->rules);
    }

    /**
     * @param  mixed  $rules  The announcement's stored `targeting_rules`.
     */
    public function passes(mixed $rules, Viewer $viewer): bool
    {
        if (! is_array($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = $rule['type'] ?? null;

            if (! is_string($type) || ! isset($this->rules[$type])) {
                continue;
            }

            if (! $this->rules[$type]->matches($rule, $viewer)) {
                return false;
            }
        }

        return true;
    }
}
