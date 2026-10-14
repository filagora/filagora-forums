<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting\Rules;

use Carbon\CarbonImmutable;
use Filagora\Forums\Support\Models;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filament\Forms\Components\TextInput;

/**
 * The user's last post is at least this many days old. A guest, and a user who never posted, do
 * not pass (use the never-posted rule for those).
 */
final class NotPostedDaysRule implements TargetingRule
{
    public function type(): string
    {
        return 'not_posted_days';
    }

    public function label(): string
    {
        return __('filagora-forums::announcements.rules.not_posted_days');
    }

    public function schema(): array
    {
        return [
            TextInput::make('days')->label(__('filagora-forums::announcements.fields.days'))->numeric()->minValue(1),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        $lastPostedAt = Models::class('post')::query()->where('author_id', $viewer->user->getAuthIdentifier())->max('created_at');

        if ($lastPostedAt === null) {
            return false;
        }

        $days = (int) ($rule['days'] ?? 0);

        return $days <= 0 || CarbonImmutable::parse($lastPostedAt)->lte(now()->subDays($days));
    }
}
