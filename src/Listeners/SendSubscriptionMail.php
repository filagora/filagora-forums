<?php

declare(strict_types=1);

namespace Filagora\Forums\Listeners;

use Filagora\Forums\Events\SubscribersToNotify;
use Filagora\Forums\Events\ThreadDigestReady;
use Filagora\Forums\Notifications\ThreadDigestNotification;
use Filagora\Forums\Notifications\ThreadUpdatedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The default email for subscription events. Silent when `filagora-forums.subscriptions.mail.enabled`
 * is off, for a host that sends its own (a bell, browser push) from the same events.
 */
final class SendSubscriptionMail
{
    public function updated(SubscribersToNotify $event): void
    {
        if ($this->enabled() && $event->recipients !== []) {
            Notification::send($event->recipients, new ThreadUpdatedNotification($event->thread, $event->post));
        }
    }

    public function digest(ThreadDigestReady $event): void
    {
        if ($this->enabled()) {
            Notification::send($event->user, new ThreadDigestNotification($event->frequency, $event->items));
        }
    }

    private function enabled(): bool
    {
        return (bool) config('filagora-forums.subscriptions.mail.enabled', true);
    }
}
