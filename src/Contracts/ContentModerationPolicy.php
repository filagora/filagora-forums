<?php

declare(strict_types=1);

namespace ForumKit\Forums\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Host rules about particular users' content. The package knows nothing about who is new or who
 * is distrusted; the host binds this. The default imposes nothing.
 */
interface ContentModerationPolicy
{
    /**
     * Everything this user posts waits for approval (a user always moderated, or a new user
     * whose first post has not been approved yet).
     */
    public function requiresModeration(Authenticatable $user): bool;

    /**
     * The user's first submission is still awaiting review, so they may not post more until it
     * is resolved.
     */
    public function awaitingFirstReview(Authenticatable $user): bool;
}
