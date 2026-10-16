<?php

declare(strict_types=1);

namespace Filagora\Forums\Search;

use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A forum was renamed, or switched on or off: bring every one of its thread documents up to date
 * (the forum's name is copied into each). Logged and swallowed on failure, like every sync.
 */
final class SyncForumThreadDocuments implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly int $forumId) {}

    public function handle(Indexer $indexer, SearchIndexRegistry $indexes): void
    {
        if (! $indexes->has(ThreadSearchIndex::NAME) || ! $indexer->isActive()) {
            return;
        }

        $index = $indexes->get(ThreadSearchIndex::NAME);

        try {
            Models::class('thread')::query()->with('forum')->where('forum_id', $this->forumId)->chunkById(200, function ($threads) use ($indexer, $index): void {
                foreach ($threads as $thread) {
                    assert($thread instanceof Thread);
                    $indexer->sync($index, $thread);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Forum thread documents could not be synced.', ['forum_id' => $this->forumId, 'exception' => $e->getMessage()]);
        }
    }
}
