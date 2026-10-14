<?php

declare(strict_types=1);

namespace Filagora\Forums\Privacy;

use Filagora\Core\Contracts\RetentionRule;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports that staff dismissed can hold user-submitted text and are not needed for ever: they are
 * deleted `retention.dismissed_reports_days` after they were reviewed (after they were made, if
 * never reviewed). Zero keeps them.
 */
final class DismissedReportsRetention implements RetentionRule
{
    public function key(): string
    {
        return 'forums.dismissed_reports';
    }

    public function prune(bool $dryRun): int
    {
        $days = (int) config('filagora-forums.retention.dismissed_reports_days', 90);

        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);

        $query = Models::class('post_report')::query()
            ->where('status', PostReportStatus::DISMISSED->value)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('reviewed_at', '<', $cutoff)
                    ->orWhere(fn (Builder $inner) => $inner->whereNull('reviewed_at')->where('created_at', '<', $cutoff));
            });

        $count = $query->count();

        if ($count > 0 && ! $dryRun) {
            $query->delete();
        }

        return $count;
    }
}
