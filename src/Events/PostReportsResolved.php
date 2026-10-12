<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use ForumKit\Forums\Enums\PostReportStatus;
use ForumKit\Forums\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every open report on a post was resolved at once. `reporterIds` lets a listener thank the
 * reporters; `threadRemoved` says the whole thread went with the post (see `ThreadDeleted`).
 */
final class PostReportsResolved
{
    use Dispatchable;

    /**
     * @param  list<int>  $reporterIds
     */
    public function __construct(
        public readonly Post $post,
        public readonly Authenticatable $moderator,
        public readonly string $outcome,
        public readonly PostReportStatus $status,
        public readonly ?string $note,
        public readonly array $reporterIds,
        public readonly bool $threadRemoved,
    ) {}
}
