<?php

declare(strict_types=1);

namespace Filagora\Forums\Commands;

use Filagora\Forums\Services\ThreadStats;
use Filagora\Forums\Support\Models;
use Illuminate\Console\Command;

class SyncThreadStatsCommand extends Command
{
    protected $signature = 'filagora:forums:sync-thread-stats
        {--thread-id=* : Limit the sync to one or more thread ids}';

    protected $description = 'Rebuild thread reply counts and last-post fields from posts';

    public function handle(ThreadStats $stats): int
    {
        $requested = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): int => (int) $value, (array) $this->option('thread-id')),
            static fn (int $id): bool => $id > 0,
        )));

        $thread = Models::class('thread');
        $query = $thread::query()->orderBy('id');

        if ($requested !== []) {
            $query->whereIn('id', $requested);
        }

        $threadIds = $query->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($threadIds === []) {
            $this->warn('No threads found to sync.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Reconciling reply counts and last-post fields for %s thread(s)...', number_format(count($threadIds))));

        $result = $stats->syncForThreadIds($threadIds, function (int $chunk, int $chunks, int $done, int $total): void {
            $this->line(sprintf('Chunk %s/%s complete (%s/%s threads).', number_format($chunk), number_format($chunks), number_format($done), number_format($total)));
        });

        $this->info(sprintf('%s/%s threads updated in %.2f seconds.', number_format($result['threads_updated']), number_format($result['thread_ids']), $result['runtime_seconds']));

        return self::SUCCESS;
    }
}
