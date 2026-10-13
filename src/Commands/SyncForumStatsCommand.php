<?php

declare(strict_types=1);

namespace Filagora\Forums\Commands;

use Filagora\Forums\Services\ForumStats;
use Filagora\Forums\Support\Models;
use Illuminate\Console\Command;

class SyncForumStatsCommand extends Command
{
    protected $signature = 'filagora:forums:sync-stats
        {--forum-id=* : Limit the sync to one or more forum ids}';

    protected $description = 'Rebuild forum counters (thread_count, post_count, last_post_*) from threads and posts';

    public function handle(ForumStats $stats): int
    {
        $requested = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): int => (int) $value, (array) $this->option('forum-id')),
            static fn (int $id): bool => $id > 0,
        )));

        $forum = Models::class('forum');
        $query = $forum::query()->orderBy('id');

        if ($requested !== []) {
            $query->whereIn('id', $requested);
        }

        $forumIds = $query->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($forumIds === []) {
            $this->warn('No forums found to sync.');

            return self::SUCCESS;
        }

        $this->line('Syncing counters for '.number_format(count($forumIds)).' forum(s)...');

        $bar = $this->output->createProgressBar(count($forumIds));
        $bar->start();

        foreach ($forumIds as $forumId) {
            $stats->syncForForumId($forumId);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Forum counters synced.');

        return self::SUCCESS;
    }
}
