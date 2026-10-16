<?php

declare(strict_types=1);

namespace Filagora\Forums\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * What the forums hold about a person, for the compliance package's export: their threads, their
 * posts, their poll votes, the forums and threads they marked read and the reports they filed. Erasure removes their subscriptions, digest queue and read markers; what they wrote
 * stays, attributed to the account that the host's anonymiser has by then made anonymous.
 */
final class ForumsDataContributor implements PrivacyContributor
{
    public function key(): string
    {
        return 'forums';
    }

    public function export(Model $user): array
    {
        $id = $user->getKey();

        return [
            'threads' => Models::class('thread')::query()->where('author_id', $id)->orderBy('id')->get(['id', 'forum_id', 'title', 'slug', 'created_at', 'updated_at'])->toArray(),
            'posts' => Models::class('post')::query()->where('author_id', $id)->orderBy('id')->get(['id', 'thread_id', 'body', 'created_at', 'updated_at'])->toArray(),
            'poll_votes' => $this->pollVotes($id),
            'reactions' => Models::class('reaction')::query()->where('user_id', $id)->orderBy('id')->get(['post_id', 'type', 'created_at'])->toArray(),
            'thread_subscriptions' => Models::class('thread_subscription')::query()->where('user_id', $id)->orderBy('id')->get(['thread_id', 'created_at'])->toArray(),
            'digest_items' => Models::class('thread_subscription_digest_item')::query()->where('user_id', $id)->orderBy('id')->get(['thread_id', 'post_id', 'sent_at', 'created_at'])->toArray(),
            'forum_reads' => Models::class('forum_read')::query()->where('user_id', $id)->orderBy('id')->get(['forum_id', 'last_read_at'])->toArray(),
            'thread_reads' => Models::class('thread_read')::query()->where('user_id', $id)->orderBy('id')->get(['thread_id', 'last_read_at', 'is_unread_override'])->toArray(),
            'post_reports' => Models::class('post_report')::query()->where('reporter_id', $id)->orderBy('id')->get(['id', 'post_id', 'reason', 'status', 'created_at'])->toArray(),
        ];
    }

    /**
     * @return list<array{poll_id: int, voted_at: string|null, option_ids: list<int>}>
     */
    private function pollVotes(mixed $userId): array
    {
        $votes = [];

        foreach (Models::class('poll_vote')::query()->where('user_id', $userId)->orderBy('id')->get() as $vote) {
            $votes[] = [
                'poll_id' => (int) $vote->getAttribute('poll_id'),
                'voted_at' => $vote->getAttribute('created_at')?->toIso8601String(),
                'option_ids' => array_values(array_map('intval', Models::class('poll_vote_answer')::query()->where('poll_vote_id', $vote->getKey())->orderBy('id')->pluck('poll_option_id')->all())),
            ];
        }

        return $votes;
    }

    public function erase(Model $user): void
    {
        // Authored content stays, under the anonymised account; what only served the person goes.
        Models::class('thread_subscription')::query()->where('user_id', $user->getKey())->delete();
        Models::class('thread_subscription_digest_item')::query()->where('user_id', $user->getKey())->delete();
        Models::class('forum_read')::query()->where('user_id', $user->getKey())->delete();
        Models::class('thread_read')::query()->where('user_id', $user->getKey())->delete();
    }
}
