<?php

declare(strict_types=1);

namespace Filagora\Forums\Notifications;

use Filagora\Forums\Enums\DeliveryMode;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadSubscriptionDigestItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The daily or weekly digest: the new posts in followed threads since the last one, grouped by thread.
 */
final class ThreadDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<ThreadSubscriptionDigestItem>  $items
     */
    public function __construct(private readonly DeliveryMode $frequency, private readonly array $items) {}

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
            ->subject(__("filagora-forums::subscriptions.digest.subject_{$this->frequency->value}"))
            ->line(__('filagora-forums::subscriptions.digest.intro', ['count' => count($this->items)]));

        $route = config('filagora-forums.subscriptions.routes.thread');
        $linkable = is_string($route) && $route !== '' && Route::has($route);

        foreach (collect($this->items)->groupBy('thread_id') as $items) {
            /** @var ThreadSubscriptionDigestItem $first */
            $first = $items->first();
            $thread = $first->thread;
            $title = $thread instanceof Thread ? $thread->title : '';
            $mail->line('**'.$title.'** ('.trans_choice('filagora-forums::subscriptions.digest.new_posts', $items->count(), ['count' => $items->count()]).')');

            foreach ($items as $item) {
                /** @var ThreadSubscriptionDigestItem $item */
                $post = $item->post;
                $mail->line('> '.Str::limit(trim(strip_tags($post instanceof Post ? $post->body : '')), 200));
            }

            if ($linkable && $thread instanceof Thread) {
                $mail->line('['.__('filagora-forums::subscriptions.digest.open').']('.route($route, ['thread' => $thread]).')');
            }
        }

        return $mail;
    }
}
