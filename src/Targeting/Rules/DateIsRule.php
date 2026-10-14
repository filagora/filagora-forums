<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\TextInput;

/**
 * Today is this month and day, by the viewer's clock.
 */
final class DateIsRule implements TargetingRule
{
    public function type(): string
    {
        return 'date_is';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.date_is');
    }

    public function schema(): array
    {
        return [
            TextInput::make('month')->label(__('filagora-forums::announcements.fields.month'))->numeric()->minValue(1)->maxValue(12),
            TextInput::make('day')->label(__('filagora-forums::announcements.fields.day'))->numeric()->minValue(1)->maxValue(31),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        $month = (int) ($rule['month'] ?? 0);
        $day = (int) ($rule['day'] ?? 0);

        if ($month <= 0 || $day <= 0) {
            return true;
        }

        $now = $viewer->now();

        return $now->month === $month && $now->day === $day;
    }
}
