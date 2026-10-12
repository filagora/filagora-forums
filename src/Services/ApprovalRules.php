<?php

declare(strict_types=1);

namespace ForumKit\Forums\Services;

use ForumKit\Forums\Contracts\ContentModerationPolicy;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Support\Abilities;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Does a new thread or reply wait for a moderator? Yes when the host's policy says the author's
 * content is always moderated, or the forum asks for approval and the author cannot bypass it.
 */
class ApprovalRules
{
    public function __construct(private readonly ContentModerationPolicy $users) {}

    public function threadRequiresApproval(Forum $forum, ?Authenticatable $user = null): bool
    {
        return $this->requires((bool) $forum->requires_thread_approval, $user);
    }

    public function replyRequiresApproval(Forum $forum, ?Authenticatable $user = null): bool
    {
        return $this->requires((bool) $forum->requires_reply_approval, $user);
    }

    private function requires(bool $forumAsksForApproval, ?Authenticatable $user): bool
    {
        if ($user !== null && $this->users->requiresModeration($user)) {
            return true;
        }

        return $forumAsksForApproval
            && ! ($user !== null && Gate::forUser($user)->allows(Abilities::BYPASS_APPROVAL));
    }
}
