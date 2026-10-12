<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator permanently deleted a thread and all its posts. The rows are gone, so the event
 * carries what a listener needs: who wrote the thread, who wrote any post in it (their post
 * counters are stale: the posts went with a database cascade, not through the model) and why.
 */
final class ThreadDeleted
{
    use Dispatchable;

    /**
     * @param  list<int>  $postAuthorIds
     */
    public function __construct(
        public readonly int $threadId,
        public readonly int $forumId,
        public readonly int $threadAuthorId,
        public readonly array $postAuthorIds,
        public readonly Authenticatable $moderator,
        public readonly ?string $reason,
    ) {}
}
