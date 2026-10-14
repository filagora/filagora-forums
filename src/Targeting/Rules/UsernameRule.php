<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\TextInput;

/**
 * Is this one user, by username (case-insensitive). A guest never is.
 */
final class UsernameRule implements TargetingRule
{
    public function type(): string
    {
        return 'username';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.username');
    }

    public function schema(): array
    {
        return [
            TextInput::make('value')->label(__('filagora-forums::announcements.fields.username')),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        $expected = trim((string) ($rule['value'] ?? ''));

        if ($expected === '') {
            return true;
        }

        $attribute = (string) config('filagora-forums.announcements.username_attribute', 'username');

        return strcasecmp((string) data_get($viewer->user, $attribute), $expected) === 0;
    }
}
