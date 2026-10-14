<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Forums\Support\Models;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\Select;

/**
 * The visitor is on a page of one forum. The host says which forum in the viewer's context
 * (`forum_id`); with none given the rule does not pass.
 */
final class BrowsingForumRule implements TargetingRule
{
    public function type(): string
    {
        return 'browsing_forum';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.browsing_forum');
    }

    public function schema(): array
    {
        return [
            Select::make('forum_id')
                ->label(__('filagora-forums::announcements.fields.forum'))
                ->options(fn (): array => Models::class('forum')::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        $forumId = $rule['forum_id'] ?? null;

        if ($forumId === null || $forumId === '') {
            return true;
        }

        return isset($viewer->context['forum_id']) && (int) $viewer->context['forum_id'] === (int) $forumId;
    }
}
