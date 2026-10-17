<?php

declare(strict_types=1);

namespace Filagora\Forums\Privacy;

use Filagora\Core\Contracts\RetentionRule;
use Filagora\Forums\Services\Attachments;

/**
 * Files uploaded for a post that was never saved are removed after `attachments.orphan_hours`
 * (24 by default; zero keeps them). The same clean-up as `filagora:forums:prune-attachments`,
 * offered to core's retention run. The service is resolved when it runs, not when the rule is
 * registered, because it needs the host's access checker.
 */
final class OrphanedAttachmentsRetention implements RetentionRule
{
    public function key(): string
    {
        return 'forums.orphaned_attachments';
    }

    public function prune(bool $dryRun): int
    {
        $hours = (int) config('filagora-forums.attachments.orphan_hours', 24);

        return $hours <= 0 ? 0 : app(Attachments::class)->pruneOrphaned($hours, $dryRun);
    }
}
