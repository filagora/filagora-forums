<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\PostModerated;
use Filagora\Forums\Exceptions\InvalidModeration;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Services\Concerns\ChecksModerator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Moderator actions on a single post that change its own state: soft delete and restore, and the
 * approval states. (Moving a post is `PostRelocation`.) Every method refuses unless the user may
 * moderate, writes one `post_moderation_logs` row, re-derives the thread's reply count and last
 * post, and dispatches `PostModerated`.
 *
 * Banning an author is a host concern: listen for `PostModerated` with `reject` or
 * `reject_permanently`.
 */
class PostModeration
{
    use ChecksModerator;

    public function __construct(
        private readonly ForumAccess $access,
        private readonly ThreadStats $threadStats,
    ) {}

    /**
     * Soft delete: the row stays, flagged and attributed. Deleting a deleted post does nothing.
     */
    public function delete(Authenticatable $moderator, Post $post, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $reason = $this->cleanReason($reason);

        $this->apply($moderator, $post, 'delete', [
            'is_deleted' => true,
            'deleted_at' => now(),
            'deleted_by_id' => $moderator->getAuthIdentifier(),
            'deleted_reason' => $reason ?? '',
        ], $reason);
    }

    /**
     * Soft delete several posts at once.
     *
     * @param  iterable<Post>  $posts
     *
     * @throws InvalidModeration When none are given or more than `PostRelocation::MAX_BATCH`.
     */
    public function deleteMany(Authenticatable $moderator, iterable $posts, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $list = [];

        foreach ($posts as $post) {
            $list[] = $post;
        }

        if ($list === [] || count($list) > PostRelocation::MAX_BATCH) {
            throw new InvalidModeration('Choose between 1 and '.PostRelocation::MAX_BATCH.' posts.');
        }

        DB::transaction(function () use ($moderator, $list, $reason): void {
            foreach ($list as $post) {
                $this->delete($moderator, $post, $reason);
            }
        });
    }

    public function restore(Authenticatable $moderator, Post $post): void
    {
        $this->authorize($moderator);

        $this->apply($moderator, $post, 'restore', [
            'is_deleted' => false,
            'deleted_at' => null,
            'deleted_by_id' => null,
            'deleted_reason' => null,
        ], null);
    }

    public function approve(Authenticatable $moderator, Post $post, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $reason = $this->cleanReason($reason);

        $this->apply($moderator, $post, 'approve', [
            'moderation_status' => ContentModerationStatus::APPROVED,
            'approved_by_id' => $moderator->getAuthIdentifier(),
            'approved_at' => now(),
            'rejected_by_id' => null,
            'rejected_at' => null,
            'moderation_reason' => $reason,
        ], $reason);
    }

    /**
     * Send an approved post back to the queue.
     */
    public function unapprove(Authenticatable $moderator, Post $post, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $reason = $this->cleanReason($reason);

        $this->apply($moderator, $post, 'unapprove', [
            'moderation_status' => ContentModerationStatus::PENDING,
            'approved_by_id' => null,
            'approved_at' => null,
            'rejected_by_id' => null,
            'rejected_at' => null,
            'moderation_reason' => $reason,
        ], $reason);
    }

    public function reject(Authenticatable $moderator, Post $post, ?string $reason = null): void
    {
        $this->rejectWith(ContentModerationStatus::REJECTED, 'reject', $moderator, $post, $reason);
    }

    /**
     * Reject with no way back for the author (`rejected_final`).
     */
    public function rejectPermanently(Authenticatable $moderator, Post $post, ?string $reason = null): void
    {
        $this->rejectWith(ContentModerationStatus::REJECTED_FINAL, 'reject_permanently', $moderator, $post, $reason);
    }

    private function rejectWith(ContentModerationStatus $status, string $action, Authenticatable $moderator, Post $post, ?string $reason): void
    {
        $this->authorize($moderator);
        $reason = $this->cleanReason($reason);

        $this->apply($moderator, $post, $action, [
            'moderation_status' => $status,
            'approved_by_id' => null,
            'approved_at' => null,
            'rejected_by_id' => $moderator->getAuthIdentifier(),
            'rejected_at' => now(),
            'moderation_reason' => $reason,
        ], $reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(Authenticatable $moderator, Post $post, string $action, array $attributes, ?string $reason): void
    {
        DB::transaction(function () use ($post, $attributes, $moderator, $action, $reason): void {
            $post->forceFill($attributes)->save();

            PostModerationLog::query()->create([
                'thread_id' => $post->thread_id,
                'post_id' => $post->getKey(),
                'moderator_id' => $moderator->getAuthIdentifier(),
                'action' => $action,
                'reason' => $reason,
            ]);

            $this->threadStats->syncForThreadIds([(int) $post->thread_id]);
        });

        PostModerated::dispatch($post, $moderator, $action, $reason);
    }
}
