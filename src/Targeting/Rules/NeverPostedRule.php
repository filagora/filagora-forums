<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Filagora\Forums\Support\Models;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;

/**
 * The user has not written a post. A guest never passes.
 */
final class NeverPostedRule implements TargetingRule
{
    public function type(): string
    {
        return 'never_posted';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.never_posted');
    }

    public function schema(): array
    {
        return [];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        return ! Models::class('post')::query()->where('author_id', $viewer->user->getAuthIdentifier())->exists();
    }
}
