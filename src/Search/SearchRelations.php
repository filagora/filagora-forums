<?php

declare(strict_types=1);

namespace Filagora\Forums\Search;

use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Search\Jobs\SyncSearchDocument;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Keeps thread documents right when something they copy from changes: a post (its text, its
 * author, the thread's reply count) or the forum (its name, whether it is active).
 */
final class SearchRelations
{
    public function postSaved(Post $post): void
    {
        $this->syncThread($post->thread_id);
    }

    public function postDeleted(Post $post): void
    {
        $this->syncThread($post->thread_id);
    }

    public function forumSaved(Forum $forum): void
    {
        if ($forum->wasChanged(['name', 'is_active'])) {
            $pending = SyncForumThreadDocuments::dispatch((int) $forum->getKey());

            $this->configure($pending);
        }
    }

    private function syncThread(int|string $threadId): void
    {
        $this->configure(SyncSearchDocument::dispatch(ThreadSearchIndex::NAME, $threadId));
    }

    private function configure(PendingDispatch $pending): void
    {
        if (! config('filagora-search.queue', false)) {
            $pending->onConnection('sync');
        }

        if (config('filagora-search.after_commit', true)) {
            $pending->afterCommit();
        }
    }
}
