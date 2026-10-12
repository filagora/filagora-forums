<?php

declare(strict_types=1);

namespace ForumKit\Forums\Services;

use Carbon\CarbonImmutable;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Events\ThreadDeleted;
use ForumKit\Forums\Events\ThreadModerated;
use ForumKit\Forums\Exceptions\InvalidModeration;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Models\ThreadModerationLog;
use ForumKit\Forums\Services\Concerns\ChecksModerator;
use ForumKit\Forums\Support\Models;
use ForumKit\Forums\Support\StatsSyncScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Moderator actions on a thread. Every method refuses unless the user may moderate, writes one
 * `thread_moderation_logs` row, keeps the counters right and dispatches `ThreadModerated`
 * (`delete` is the exception: the log row would cascade away with the thread, so it dispatches
 * `ThreadDeleted` instead).
 */
class ThreadModeration
{
    use ChecksModerator;

    public function __construct(
        private readonly ForumAccess $access,
        private readonly ThreadCreation $threads,
        private readonly ThreadStats $threadStats,
    ) {}

    public function lock(Authenticatable $moderator, Thread $thread, ?string $reason = null): void
    {
        $this->flag($moderator, $thread, 'is_locked', true, 'lock', $reason);
    }

    public function unlock(Authenticatable $moderator, Thread $thread, ?string $reason = null): void
    {
        $this->flag($moderator, $thread, 'is_locked', false, 'unlock', $reason);
    }

    public function pin(Authenticatable $moderator, Thread $thread, ?string $reason = null): void
    {
        $this->flag($moderator, $thread, 'is_pinned', true, 'pin', $reason);
    }

    public function unpin(Authenticatable $moderator, Thread $thread, ?string $reason = null): void
    {
        $this->flag($moderator, $thread, 'is_pinned', false, 'unpin', $reason);
    }

    /**
     * Move the thread to another forum.
     *
     * @throws InvalidModeration When it is already there.
     */
    public function move(Authenticatable $moderator, Thread $thread, Forum $destination, ?string $reason = null): void
    {
        $this->authorize($moderator);

        if ((int) $thread->forum_id === (int) $destination->getKey()) {
            throw new InvalidModeration('The thread is already in that forum.');
        }

        DB::transaction(function () use ($thread, $destination): void {
            $thread->forum_id = $destination->getKey();
            $thread->save();
        });

        $this->finish($moderator, $thread, 'move', $reason);
    }

    /**
     * Change the title and re-derive the slug (kept unique). The log reason records old and new
     * title. Renaming to the current title does nothing but is still logged.
     */
    public function rename(Authenticatable $moderator, Thread $thread, string $title): void
    {
        $this->authorize($moderator);
        $title = trim($title);

        if ($title === '') {
            throw new InvalidModeration('A thread needs a title.');
        }

        $reason = null;
        $old = (string) $thread->title;

        if ($title !== $old) {
            $thread->title = $title;
            $thread->slug = $this->threads->uniqueSlug($title, (int) $thread->getKey());
            $thread->save();
            $reason = sprintf('Renamed from "%s" to "%s"', $old, $title);
        }

        $this->finish($moderator, $thread, 'rename', $reason);
    }

    /**
     * Approve the thread and its opening post together.
     */
    public function approve(Authenticatable $moderator, Thread $thread, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $reason = $this->cleanReason($reason);
        $moderatorId = $moderator->getAuthIdentifier();
        $postModel = Models::class('post');

        DB::transaction(function () use ($thread, $moderatorId, $reason, $postModel): void {
            $starterId = $postModel::query()
                ->where('thread_id', $thread->getKey())
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('id');

            if ($starterId !== null) {
                StatsSyncScope::withoutPostSync(fn () => $postModel::query()->whereKey($starterId)->update([
                    'moderation_status' => ContentModerationStatus::APPROVED->value,
                    'approved_by_id' => $moderatorId,
                    'approved_at' => now(),
                    'rejected_by_id' => null,
                    'rejected_at' => null,
                    'moderation_reason' => $reason,
                    'updated_at' => now(),
                ]));
            }

            // The thread save recomputes the forum, which sees the approved opening post.
            $thread->forceFill([
                'moderation_status' => ContentModerationStatus::APPROVED,
                'approved_by_id' => $moderatorId,
                'approved_at' => now(),
                'rejected_by_id' => null,
                'rejected_at' => null,
                'moderation_reason' => $reason,
            ])->save();

            $this->threadStats->syncForThreadIds([(int) $thread->getKey()]);
        });

        $thread->refresh();
        $this->finish($moderator, $thread, 'approve', $reason);
    }

    /**
     * Permanently delete the thread and all its posts ("reject permanently"). The outcome is
     * written onto the thread first, so audit listeners on the model see who removed it and why.
     */
    public function delete(Authenticatable $moderator, Thread $thread, ?string $reason = null): void
    {
        $this->authorize($moderator);
        $reason = $this->cleanReason($reason);
        $postModel = Models::class('post');

        $postAuthorIds = $postModel::query()
            ->where('thread_id', $thread->getKey())
            ->distinct()
            ->pluck('author_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($thread, $moderator, $reason): void {
            StatsSyncScope::withoutThreadSync(fn () => $thread->forceFill([
                'moderation_status' => ContentModerationStatus::REJECTED_FINAL,
                'approved_by_id' => null,
                'approved_at' => null,
                'rejected_by_id' => $moderator->getAuthIdentifier(),
                'rejected_at' => CarbonImmutable::now(),
                'moderation_reason' => $reason,
            ])->save());

            $thread->delete();
        });

        ThreadDeleted::dispatch(
            (int) $thread->getKey(),
            (int) $thread->forum_id,
            (int) $thread->author_id,
            $postAuthorIds,
            $moderator,
            $reason,
        );
    }

    private function flag(Authenticatable $moderator, Thread $thread, string $column, bool $value, string $action, ?string $reason): void
    {
        $this->authorize($moderator);

        if ((bool) $thread->{$column} !== $value) {
            $thread->{$column} = $value;
            $thread->save();
        }

        $this->finish($moderator, $thread, $action, $reason);
    }

    private function finish(Authenticatable $moderator, Thread $thread, string $action, ?string $reason): void
    {
        $reason = $this->cleanReason($reason);

        ThreadModerationLog::query()->create([
            'thread_id' => $thread->getKey(),
            'moderator_id' => $moderator->getAuthIdentifier(),
            'action' => $action,
            'reason' => $reason,
        ]);

        ThreadModerated::dispatch($thread, $moderator, $action, $reason);
    }
}
