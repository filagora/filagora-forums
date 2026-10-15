<?php

declare(strict_types=1);

namespace Filagora\Forums\Notifications;

use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Tells a follower there is a new post in a thread they follow.
 */
final class ThreadUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Thread $thread, private readonly Post $post) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('filagora-forums::subscriptions.updated.subject', ['thread' => $this->thread->title]))
            ->line(__('filagora-forums::subscriptions.updated.intro', ['thread' => $this->thread->title]))
            ->line(Str::limit(trim(strip_tags($this->post->body)), 300));

        $name = config('filagora-forums.subscriptions.routes.thread');

        return is_string($name) && $name !== '' && Route::has($name)
            ? $mail->action(__('filagora-forums::subscriptions.updated.action'), route($name, ['thread' => $this->thread]))
            : $mail;
    }
}
