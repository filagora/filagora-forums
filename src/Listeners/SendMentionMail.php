<?php

declare(strict_types=1);

namespace Filagora\Forums\Listeners;

use Filagora\Forums\Events\UsersMentioned;
use Filagora\Forums\Notifications\UserMentionedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The default email for a mention. Silent when `filagora-forums.mentions.mail.enabled` is off, for
 * a host that notifies through its own channels from the same event.
 */
final class SendMentionMail
{
    public function handle(UsersMentioned $event): void
    {
        if ((bool) config('filagora-forums.mentions.mail.enabled', true) && $event->users !== []) {
            Notification::send($event->users, new UserMentionedNotification($event->post));
        }
    }
}
