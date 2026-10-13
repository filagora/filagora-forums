<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

/**
 * Which forums a viewer can see, and in which of them only their own threads.
 */
final readonly class ForumViewerScope
{
    /**
     * @param  bool  $unrestricted  Staff acting as staff see everything.
     * @param  list<int>  $fullForumIds  Forums the viewer sees in full.
     * @param  list<int>  $ownThreadsForumIds  Forums where the viewer sees only their own threads.
     */
    public function __construct(
        public bool $unrestricted,
        public array $fullForumIds,
        public array $ownThreadsForumIds,
    ) {}
}
