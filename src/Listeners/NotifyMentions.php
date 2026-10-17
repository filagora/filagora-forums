<?php

declare(strict_types=1);

namespace Filagora\Forums\Listeners;

use Filagora\Forums\Events\PostCreated;
use Filagora\Forums\Events\PostEdited;
use Filagora\Forums\Events\PostModerated;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Services\Mentions;
use Filagora\Forums\Support\Models;

/**
 * Turns posting into mentions: a new approved post, an edit, a post that is approved or restored
 * later, and the posts of a thread when it is approved. `Mentions::sync()` ignores anything not
 * visible and anyone already told, so each trigger can simply ask. Switch off with `mentions.enabled`.
 */
final class NotifyMentions
{
    public function __construct(private readonly Mentions $mentions) {}

    public function postCreated(PostCreated $event): void
    {
        if ($this->enabled() && ! $event->requiresApproval) {
            $this->mentions->sync($event->post);
        }
    }

    public function postEdited(PostEdited $event): void
    {
        if ($this->enabled()) {
            $this->mentions->sync($event->post);
        }
    }

    public function postModerated(PostModerated $event): void
    {
        if ($this->enabled() && in_array($event->action, ['approve', 'restore'], true)) {
            $this->mentions->sync($event->post);
        }
    }

    public function threadModerated(ThreadModerated $event): void
    {
        if (! $this->enabled() || $event->action !== 'approve') {
            return;
        }

        foreach (Models::class('post')::query()->where('thread_id', $event->thread->getKey())->orderBy('id')->lazyById(100) as $post) {
            if ($post instanceof Post) {
                $this->mentions->sync($post);
            }
        }
    }

    private function enabled(): bool
    {
        return (bool) config('filagora-forums.mentions.enabled', true);
    }
}
