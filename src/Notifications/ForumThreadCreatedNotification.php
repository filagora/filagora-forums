<?php

declare(strict_types=1);

namespace Filagora\Forums\Notifications;

use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * A new thread in a forum someone follows.
 */
final class ForumThreadCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Thread $thread) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $forum = $this->thread->forum;
        $replace = ['thread' => $this->thread->title, 'forum' => $forum instanceof Forum ? $forum->name : ''];
        $mail = (new MailMessage)
            ->subject(__('filagora-forums::subscriptions.new_thread.subject', $replace))
            ->line(__('filagora-forums::subscriptions.new_thread.intro', $replace));

        $name = config('filagora-forums.subscriptions.routes.thread');

        return is_string($name) && $name !== '' && Route::has($name)
            ? $mail->action(__('filagora-forums::subscriptions.new_thread.action'), route($name, ['thread' => $this->thread]))
            : $mail;
    }
}
