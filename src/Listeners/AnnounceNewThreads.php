<?php

declare(strict_types=1);

namespace Filagora\Forums\Listeners;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\ThreadCreated;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Events\ThreadPromoted;
use Filagora\Forums\Jobs\AnnounceThreadToForumSubscribers;
use Filagora\Forums\Models\Thread;

/**
 * A thread becomes visible in a forum in four ways: started without moderation, approved, moved in,
 * or made from a reply. Each queues the news for that forum's followers.
 */
final class AnnounceNewThreads
{
    public function threadCreated(ThreadCreated $event): void
    {
        if (! $event->requiresApproval) {
            $this->announce($event->thread);
        }
    }

    public function threadModerated(ThreadModerated $event): void
    {
        if (in_array($event->action, ['approve', 'move'], true)) {
            $this->announce($event->thread);
        }
    }

    public function threadPromoted(ThreadPromoted $event): void
    {
        $this->announce($event->thread);
    }

    private function announce(Thread $thread): void
    {
        if ($thread->moderation_status === ContentModerationStatus::APPROVED) {
            AnnounceThreadToForumSubscribers::dispatch($thread->getKey())->afterCommit();
        }
    }
}
