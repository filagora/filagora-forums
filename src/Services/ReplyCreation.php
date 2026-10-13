<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Contracts\PostSignatureResolver;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\PostCreated;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Filagora\Forums\Support\StatsSyncScope;
use Filagora\Forums\ValueObjects\CreatedReply;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Creates a reply, applying the forum's approval rules and keeping the thread and forum counters
 * right. Does not check who may reply: see `Posting` for the checked entry point.
 */
class ReplyCreation
{
    public function __construct(
        private readonly ApprovalRules $approval,
        private readonly ForumStats $stats,
        private readonly PostSignatureResolver $signatures,
    ) {}

    public function create(Thread $thread, Authenticatable $author, string $body): CreatedReply
    {
        $body = trim($body);
        $postModel = Models::class('post');
        $authorId = $author->getAuthIdentifier();

        /** @var Post|null $duplicate */
        $duplicate = $postModel::query()
            ->where('thread_id', $thread->getKey())
            ->where('author_id', $authorId)
            ->where('body', $body)
            ->where('created_at', '>=', now()->subSeconds(ThreadCreation::DUPLICATE_SUBMISSION_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if ($duplicate !== null) {
            return new CreatedReply($duplicate, $duplicate->moderation_status !== ContentModerationStatus::APPROVED, duplicate: true);
        }

        $forum = $thread->forum;
        assert($forum instanceof Forum);

        $requiresApproval = $this->approval->replyRequiresApproval($forum, $author);
        $status = $requiresApproval ? ContentModerationStatus::PENDING : ContentModerationStatus::APPROVED;
        $signature = $this->signatures->signatureFor($author);

        /** @var Post $post */
        $post = DB::transaction(function () use ($thread, $forum, $authorId, $body, $status, $requiresApproval, $signature, $postModel): Post {
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
                $approvedPosts = $postModel::query()
                    ->where('thread_id', $thread->getKey())
                    ->where('is_deleted', false)
                    ->where('moderation_status', ContentModerationStatus::APPROVED->value)
                    ->count();

                $thread->last_post_author_id = $authorId;
                $thread->last_post_at = $post->created_at;
                $thread->reply_count = max(0, $approvedPosts - 1);
                StatsSyncScope::withoutThreadSync(fn (): bool => $thread->save());

                $this->stats->incrementForApprovedPost($forum, $post, incrementThreadCount: false);
            }

            return $post->refresh();
        });

        PostCreated::dispatch($post, $thread, $requiresApproval, false);

        return new CreatedReply($post, $requiresApproval);
    }
}
