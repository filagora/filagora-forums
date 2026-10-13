<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Carbon\CarbonImmutable;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\PostingDenial;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Events\PostReported;
use Filagora\Forums\Events\PostReportsResolved;
use Filagora\Forums\Exceptions\InvalidModeration;
use Filagora\Forums\Exceptions\PostingNotPermitted;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\Concerns\ChecksModerator;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Members report posts; moderators resolve every open report on a post in one step.
 */
class PostReports
{
    use ChecksModerator;

    public const DISMISS = 'dismiss';

    public const KEEP = 'keep';

    public const REMOVE = 'remove';

    public const REMOVE_THREAD = 'remove_thread';

    /** @var list<string> */
    public const OUTCOMES = [self::DISMISS, self::KEEP, self::REMOVE, self::REMOVE_THREAD];

    public function __construct(
        private readonly ForumAccess $access,
        private readonly PostModeration $posts,
        private readonly ThreadModeration $threads,
    ) {}

    /**
     * Report a post. The reporter must be able to see it, and only approved, undeleted posts can
     * be reported.
     *
     * @throws PostingNotPermitted When the reporter cannot see the post.
     * @throws InvalidModeration When the post cannot be reported, or this user already has an open report on it.
     */
    public function report(Authenticatable $reporter, Post $post, string $reason): PostReport
    {
        $thread = $post->thread;
        assert($thread instanceof Thread);
        $forum = $thread->forum;
        assert($forum instanceof Forum);

        if (! $this->access->canViewForum($forum, $reporter)) {
            throw new PostingNotPermitted(PostingDenial::FORUM_NOT_VISIBLE);
        }

        if (! $this->access->canAccessThread($thread, $reporter)) {
            throw new PostingNotPermitted(PostingDenial::THREAD_NOT_ACCESSIBLE);
        }

        if ($post->is_deleted) {
            throw new InvalidModeration('This post is no longer there to report.');
        }

        if ($post->moderation_status !== ContentModerationStatus::APPROVED) {
            throw new InvalidModeration('Only approved posts can be reported.');
        }

        $alreadyReported = PostReport::query()
            ->where('post_id', $post->getKey())
            ->where('reporter_id', $reporter->getAuthIdentifier())
            ->where('status', PostReportStatus::OPEN->value)
            ->exists();

        if ($alreadyReported) {
            throw new InvalidModeration('You have already reported this post.');
        }

        $report = PostReport::query()->create([
            'post_id' => $post->getKey(),
            'thread_id' => $post->thread_id,
            'reporter_id' => $reporter->getAuthIdentifier(),
            'reason' => trim($reason),
            'status' => PostReportStatus::OPEN->value,
        ]);

        PostReported::dispatch($report);

        return $report;
    }

    /**
     * Resolve all open reports on the post. `dismiss` marks them dismissed; the other outcomes
     * mark them reviewed. `remove` soft-deletes the post. `remove_thread` deletes the whole
     * thread when this post is the only approved, undeleted one in it, and otherwise behaves
     * as `remove`. An unknown outcome counts as `keep`.
     */
    public function resolve(Authenticatable $moderator, Post $post, string $outcome, ?string $note = null): void
    {
        $this->authorize($moderator);

        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : self::KEEP;
        $note = $this->cleanReason($note);
        $status = $outcome === self::DISMISS ? PostReportStatus::DISMISSED : PostReportStatus::REVIEWED;
        $removeThread = $outcome === self::REMOVE_THREAD && $this->isOnlyPost($post);

        $open = PostReport::query()
            ->where('post_id', $post->getKey())
            ->where('status', PostReportStatus::OPEN->value);
        $reporterIds = $open->pluck('reporter_id')->map(static fn ($id): int => (int) $id)->all();

        DB::transaction(function () use ($open, $status, $note, $moderator, $post, $outcome, $removeThread): void {
            $open->update([
                'status' => $status->value,
                'resolution_note' => $note,
                'reviewed_by_id' => $moderator->getAuthIdentifier(),
                'reviewed_at' => CarbonImmutable::now(),
            ]);

            if ($removeThread) {
                $thread = $post->thread;
                assert($thread instanceof Thread);
                $this->threads->delete($moderator, $thread, $note);
            } elseif (in_array($outcome, [self::REMOVE, self::REMOVE_THREAD], true) && ! $post->is_deleted) {
                $this->posts->delete($moderator, $post, $note ?? 'Removed after report');
            }
        });

        PostReportsResolved::dispatch($post, $moderator, $outcome, $status, $note, $reporterIds, $removeThread);
    }

    /**
     * True when removing this post would leave its thread with no approved, undeleted post.
     */
    public function isOnlyPost(Post $post): bool
    {
        return Models::class('post')::query()
            ->where('thread_id', $post->thread_id)
            ->where('is_deleted', false)
            ->where('moderation_status', ContentModerationStatus::APPROVED->value)
            ->count() <= 1;
    }
}
