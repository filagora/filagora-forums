<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\TimePicker;

/**
 * The time of day is inside a window, by the viewer's clock. A window that ends before it starts
 * runs overnight (22:00 to 02:00).
 */
final class TimeBetweenRule implements TargetingRule
{
    public function type(): string
    {
        return 'time_between';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.time_between');
    }

    public function schema(): array
    {
        return [
            TimePicker::make('start')->label(__('filagora-forums::announcements.fields.start'))->seconds(false),
            TimePicker::make('end')->label(__('filagora-forums::announcements.fields.end'))->seconds(false),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        $start = trim((string) ($rule['start'] ?? ''));
        $end = trim((string) ($rule['end'] ?? ''));

        if ($start === '' || $end === '') {
            return true;
        }

        $now = $viewer->now()->format('H:i');

        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        return $now >= $start || $now <= $end;
    }
}
