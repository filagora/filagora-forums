<?php

declare(strict_types=1);

namespace ForumKit\Forums\Services;

use ForumKit\Forums\Contracts\PostSignatureResolver;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Events\PostCreated;
use ForumKit\Forums\Events\ThreadCreated;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Support\Models;
use ForumKit\Forums\Support\StatsSyncScope;
use ForumKit\Forums\ValueObjects\CreatedThread;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a thread together with its first post, applying the forum's approval rules and keeping
 * the forum and thread counters right. Does not check who may post: see `Posting` for the checked
 * entry point; this is for flows that start a thread on someone's behalf.
 */
class ThreadCreation
{
    /**
     * An identical title and body from the same author in the same forum within this many seconds
     * is a double submission (a double-click, a back-button resubmit), not a deliberate repost.
     */
    public const DUPLICATE_SUBMISSION_WINDOW_SECONDS = 15;

    public function __construct(
        private readonly ApprovalRules $approval,
        private readonly ForumStats $stats,
        private readonly PostSignatureResolver $signatures,
    ) {}

    /**
     * @param  bool  $forcePending  Hold the thread for approval whatever the rules say.
     * @param  bool  $subscribeAuthor  Passed to the event: a subscriptions add-on follows it.
     */
    public function create(
        Forum $forum,
        Authenticatable $author,
        string $title,
        string $body,
        bool $forcePending = false,
        bool $lockThread = false,
        bool $subscribeAuthor = true,
    ): CreatedThread {
        $title = trim($title);
        $body = trim($body);
        $requiresApproval = $forcePending || $this->approval->threadRequiresApproval($forum, $author);
        $status = $requiresApproval ? ContentModerationStatus::PENDING : ContentModerationStatus::APPROVED;
        $authorId = $author->getAuthIdentifier();
        $signature = $this->signatures->signatureFor($author);
        $threadModel = Models::class('thread');
        $postModel = Models::class('post');

        [$thread, $post] = DB::transaction(function () use ($forum, $authorId, $title, $body, $status, $requiresApproval, $lockThread, $signature, $threadModel, $postModel): array {
            /** @var Thread $thread */
            $thread = StatsSyncScope::withoutThreadSync(fn () => $threadModel::query()->create([
                'forum_id' => $forum->getKey(),
                'author_id' => $authorId,
                'title' => $title,
                'slug' => $this->uniqueSlug($title),
                'moderation_status' => $status,
                'is_pinned' => false,
                'is_locked' => $lockThread,
                'reply_count' => 0,
                'approved_by_id' => $requiresApproval ? null : $authorId,
                'approved_at' => $requiresApproval ? null : now(),
            ]));

            /** @var Post $post */
            $post = StatsSyncScope::withoutPostSync(fn () => $postModel::query()->create([
                'thread_id' => $thread->getKey(),
                'author_id' => $authorId,
                'body' => $body,
                'content_format' => 'markdown',
                'moderation_status' => $status,
                'signature' => $signature,
                'signature_format' => $signature !== null ? 'markdown' : null,
                'approved_by_id' => $requiresApproval ? null : $authorId,
                'approved_at' => $requiresApproval ? null : now(),
            ]));

            if (! $requiresApproval) {
                StatsSyncScope::withoutThreadSync(fn (): bool => $thread->update([
                    'last_post_author_id' => $authorId,
                    'last_post_at' => $post->created_at,
                ]));

                $this->stats->incrementForApprovedPost($forum, $post, incrementThreadCount: true);
            }

            return [$thread->refresh(), $post->refresh()];
        });

        ThreadCreated::dispatch($thread, $post, $requiresApproval, $subscribeAuthor);
        PostCreated::dispatch($post, $thread, $requiresApproval, true);

        return new CreatedThread($thread, $post, $requiresApproval);
    }

    /**
     * The first post of a thread this author started in this forum with the same title and body
     * moments ago, if there is one. Check before `create()` and send the author there instead of
     * creating a second identical thread.
     */
    public function findDuplicateFirstPost(Forum $forum, Authenticatable $author, string $title, string $body): ?Post
    {
        $threadModel = Models::class('thread');

        /** @var Thread|null $candidate */
        $candidate = $threadModel::query()
            ->where('forum_id', $forum->getKey())
            ->where('author_id', $author->getAuthIdentifier())
            ->where('title', trim($title))
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_SUBMISSION_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if ($candidate === null) {
            return null;
        }

        /** @var Post|null $first */
        $first = $candidate->posts()->oldest('id')->first();

        return $first !== null && trim($first->body) === trim($body) ? $first : null;
    }

    /**
     * A slug from the title that no other thread has: `hello`, then `hello-2`, `hello-3`...
     */
    public function uniqueSlug(string $title, ?int $exceptThreadId = null): string
    {
        $base = Str::slug($title);

        // Leave room for the numeric suffix inside the column's 255 characters.
        $base = $base === '' ? 'thread' : mb_substr($base, 0, 240);
        $threadModel = Models::class('thread');
        $slug = $base;
        $suffix = 2;

        while (
            $threadModel::query()
                ->where('slug', $slug)
                ->when($exceptThreadId !== null, fn ($query) => $query->where('id', '!=', $exceptThreadId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
