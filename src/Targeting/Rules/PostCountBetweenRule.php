<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Forums\Support\Models;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\TextInput;

/**
 * The user's number of posts is within a range (either end may be left open). A guest never passes.
 */
final class PostCountBetweenRule implements TargetingRule
{
    public function type(): string
    {
        return 'post_count_between';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.post_count_between');
    }

    public function schema(): array
    {
        return [
            TextInput::make('min')->label(__('filagora-forums::announcements.fields.min_posts'))->numeric(),
            TextInput::make('max')->label(__('filagora-forums::announcements.fields.max_posts'))->numeric(),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        $count = Models::class('post')::query()->where('author_id', $viewer->user->getAuthIdentifier())->count();
        $min = $rule['min'] ?? null;
        $max = $rule['max'] ?? null;

        return ($min === null || $min === '' || $count >= (int) $min)
            && ($max === null || $max === '' || $count <= (int) $max);
    }
}
