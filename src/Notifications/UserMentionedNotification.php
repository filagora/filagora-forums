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
 * Tells someone they were mentioned in a post.
 */
final class UserMentionedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Post $post) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $thread = $this->post->thread;
        $title = $thread instanceof Thread ? $thread->title : '';
        $mail = (new MailMessage)
            ->subject(__('filagora-forums::mentions.subject', ['thread' => $title]))
            ->line(__('filagora-forums::mentions.intro', ['thread' => $title]))
            ->line(Str::limit(trim(strip_tags($this->post->body)), 300));

        $name = config('filagora-forums.mentions.routes.thread');

        return $thread instanceof Thread && is_string($name) && $name !== '' && Route::has($name)
            ? $mail->action(__('filagora-forums::mentions.action'), route($name, ['thread' => $thread]).'#post-'.$this->post->getKey())
            : $mail;
    }
}
