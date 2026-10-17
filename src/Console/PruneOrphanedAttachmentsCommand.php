<?php

declare(strict_types=1);

namespace Filagora\Forums\Console;

use Filagora\Forums\Services\Attachments;
use Illuminate\Console\Command;

/**
 * Removes files that were uploaded for a post that was never saved.
 */
final class PruneOrphanedAttachmentsCommand extends Command
{
    protected $signature = 'filagora:forums:prune-attachments {--hours= : Hours a file may wait for its post (default from config)} {--dry-run : Report the count without deleting}';

    protected $description = 'Delete uploaded files that were never attached to a post.';

    public function handle(Attachments $attachments): int
    {
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : (int) config('filagora-forums.attachments.orphan_hours', 24);
        $dryRun = (bool) $this->option('dry-run');

        $count = $attachments->pruneOrphaned($hours, $dryRun);

        $this->info(($dryRun ? 'Would remove ' : 'Removed ')."{$count} unclaimed attachment(s).");

        return self::SUCCESS;
    }
}
