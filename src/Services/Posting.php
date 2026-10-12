<?php

declare(strict_types=1);

namespace ForumKit\Forums\Services;

use ForumKit\Forums\Contracts\ContentModerationPolicy;
use ForumKit\Forums\Enums\PostingDenial;
use ForumKit\Forums\Events\PostEdited;
use ForumKit\Forums\Exceptions\PostingNotPermitted;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\ValueObjects\CreatedReply;
use ForumKit\Forums\ValueObjects\CreatedThread;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The checked way to start a thread, reply and edit a post: every call first asks `ForumAccess`
 * whether this user may, and refuses with `PostingNotPermitted` (carrying a `PostingDenial`
 * reason) when they may not. A frontend validates input, calls this, and reacts to the outcome.
 */
class Posting
{
    public function __construct(
        private readonly ForumAccess $access,
        private readonly ThreadCreation $threads,
        private readonly ReplyCreation $replies,
        private readonly ContentModerationPolicy $users,
    ) {}

    /**
     * Start a thread. Only users acting as staff may hold a thread for approval or lock it from
     * the start; the options are ignored for anyone else.
     *
     * @throws PostingNotPermitted
     */
    public function startThread(
        Authenticatable $user,
        Forum $forum,
        string $title,
        string $body,
        bool $postAsUnapproved = false,
        bool $lockThread = false,
        bool $subscribeAuthor = true,
    ): CreatedThread {
        $authored = $this->access->authoredForumIds($user);

        if (! $this->access->canViewForum($forum, $user, $authored)) {
            throw new PostingNotPermitted(PostingDenial::FORUM_NOT_VISIBLE);
        }

        if (! $this->access->canPostInForum($forum, $user, $authored)) {
            throw new PostingNotPermitted(PostingDenial::NOT_ALLOWED);
        }

        if ($this->users->awaitingFirstReview($user)) {
            throw new PostingNotPermitted(PostingDenial::AWAITING_FIRST_REVIEW);
        }

        $duplicate = $this->threads->findDuplicateFirstPost($forum, $user, $title, $body);

        if ($duplicate !== null) {
            $thread = $duplicate->thread;
            assert($thread instanceof Thread);

            return new CreatedThread($thread, $duplicate, $thread->isPending(), duplicate: true);
        }

        $isStaff = $this->access->actsAsStaff($user);

        return $this->threads->create(
            $forum,
            $user,
            $title,
            $body,
            forcePending: $isStaff && $postAsUnapproved,
            lockThread: $isStaff && $lockThread,
            subscribeAuthor: $subscribeAuthor,
        );
    }

    /**
     * Reply to a thread.
     *
     * @throws PostingNotPermitted
     */
    public function reply(Authenticatable $user, Thread $thread, string $body): CreatedReply
    {
        $thread->loadMissing('forum');
        $forum = $thread->forum;
        assert($forum instanceof Forum);
        $authored = $this->access->authoredForumIds($user);

        if (! $this->access->canViewForum($forum, $user, $authored)) {
            throw new PostingNotPermitted(PostingDenial::FORUM_NOT_VISIBLE);
        }

        if (! $this->access->canAccessThread($thread, $user)) {
            throw new PostingNotPermitted(PostingDenial::THREAD_NOT_ACCESSIBLE);
        }

        if ($thread->is_locked) {
            throw new PostingNotPermitted(PostingDenial::THREAD_LOCKED);
        }

        if (! $thread->isApproved()) {
            throw new PostingNotPermitted(PostingDenial::THREAD_NOT_APPROVED);
        }

        if (! $this->access->canReplyToThread($thread, $user, $authored)) {
            throw new PostingNotPermitted(PostingDenial::NOT_ALLOWED);
        }

        if ($this->users->awaitingFirstReview($user)) {
            throw new PostingNotPermitted(PostingDenial::AWAITING_FIRST_REVIEW);
        }

        return $this->replies->create($thread, $user, $body);
    }

    /**
     * Change a post's body. A post is edited by staff at any time, and by its author within the
     * edit window. Returns whether anything changed (an identical body is not an edit).
     *
     * @throws PostingNotPermitted
     */
    public function editPost(Authenticatable $user, Post $post, string $body): bool
    {
        if (! $this->access->canEditPost($post, $user)) {
            throw new PostingNotPermitted(PostingDenial::NOT_ALLOWED);
        }

        $body = trim($body);

        if ($body === (string) $post->body) {
            return false;
        }

        $post->forceFill([
            'body' => $body,
            'content_format' => 'markdown',
            'edited_at' => now(),
            'edited_by_id' => $user->getAuthIdentifier(),
        ])->save();

        PostEdited::dispatch($post, $user);

        return true;
    }
}
