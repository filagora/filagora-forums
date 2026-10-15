<?php

declare(strict_types=1);

namespace Filagora\Forums\Listeners;

use Filagora\Forums\Events\PostCreated;
use Filagora\Forums\Events\ThreadCreated;
use Filagora\Forums\Services\Subscriptions;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Wires subscriptions into posting: a new thread's author follows it when they asked to (or by
 * default), a reply's author follows the thread when their preference says so, and a new approved
 * reply is turned into updates for the followers. The host calls `Subscriptions::subscribe()`
 * itself for a "follow this thread" box on a reply form; the preference covers the standing case.
 */
final class FollowThreadActivity
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function threadCreated(ThreadCreated $event): void
    {
        $author = $event->thread->author;

        if ($event->subscribeAuthor && $author instanceof Authenticatable) {
            $this->subscriptions->subscribe($author, $event->thread);
        }
    }

    public function postCreated(PostCreated $event): void
    {
        if ($event->isFirstPost) {
            return;
        }

        $author = $event->post->author;

        if ($author instanceof Authenticatable && $this->subscriptions->shouldFollowOnPost($author)) {
            $this->subscriptions->subscribe($author, $event->thread);
        }

        if (! $event->requiresApproval) {
            $this->subscriptions->announce($event->post);
        }
    }
}
