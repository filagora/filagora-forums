<?php

declare(strict_types=1);

namespace ForumKit\Forums\Services;

use ForumKit\Entitlements\Services\AuthorizationService;
use ForumKit\Forums\Contracts\PaidAccessCheck;
use ForumKit\Forums\Contracts\StaffPreview;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Enums\ForumPostingPolicy;
use ForumKit\Forums\Enums\ForumVisibility;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Support\Abilities;
use ForumKit\Forums\Support\ForumViewerScope;
use ForumKit\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who can see, open, post in and reply to forums and threads. The single place these rules
 * live, so every screen, API and search agrees about what a viewer may see.
 *
 * The staff concept comes from three Gate abilities (see `Abilities`) and the `StaffPreview`
 * contract; "paid" access comes from the `PaidAccessCheck` contract. Bind bound per request
 * (scoped): the forums a user has authored in are remembered for the request.
 */
class ForumAccess
{
    /** @var array<string, list<int>> */
    private array $authoredForumIds = [];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly PaidAccessCheck $paid,
        private readonly StaffPreview $preview,
    ) {}

    /**
     * Holds any staff entitlement, whatever the preview switch says.
     */
    public function isStaff(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->any(Abilities::staff());
    }

    /**
     * Staff who have not switched their privileges off: they see and post everywhere.
     */
    public function actsAsStaff(?Authenticatable $user): bool
    {
        return $this->isStaff($user) && $this->preview->enabledFor($user);
    }

    /**
     * Holds the moderate entitlement itself (the other staff entitlements are not enough).
     */
    public function canModerate(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows(Abilities::MODERATE);
    }

    /**
     * May this user change the post's body? Moderators may at any time; the author only within the
     * edit window; and in either case the user must be able to see the thread.
     */
    public function canEditPost(Post $post, ?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $thread = $post->thread;
        assert($thread instanceof Thread);
        $forum = $this->forumOf($thread);

        if (! $this->canViewForum($forum, $user) || ! $this->canAccessThread($thread, $user)) {
            return false;
        }

        return $this->canModerate($user) || $post->canBeEditedByAuthor($user);
    }

    /**
     * Can the viewer see the forum in the forum list and open it?
     *
     * @param  list<int>|null  $authoredForumIds  The forums the viewer has started a thread in; looked up when omitted.
     */
    public function canViewForum(Forum $forum, ?Authenticatable $user, ?array $authoredForumIds = null): bool
    {
        if (! $forum->is_active) {
            return false;
        }

        if ($this->actsAsStaff($user)) {
            return true;
        }

        return match ($forum->visibility) {
            ForumVisibility::PUBLIC => true,
            ForumVisibility::REGISTERED => $user !== null,
            ForumVisibility::STAFF_ONLY => false,
            ForumVisibility::ENTITLEMENT_ONLY => $this->holdsEntitlement($user, $forum->required_entitlement_scope),
            ForumVisibility::PAID_ONLY => $this->hasPaidForumAccess($forum, $user),
            ForumVisibility::PAID_OR_AUTHOR => $user !== null,
            ForumVisibility::STAFF_AUTHOR_ONLY => $user !== null && (
                in_array((int) $forum->getKey(), $authoredForumIds ?? $this->authoredForumIds($user), true)
                || ($forum->show_when_empty && $this->isForumEmpty($forum))
            ),
        };
    }

    /**
     * Can the viewer open this thread? (Whether they may see its forum is canViewForum().)
     */
    public function canAccessThread(Thread $thread, ?Authenticatable $user): bool
    {
        if ($this->actsAsStaff($user)) {
            return true;
        }

        if ($thread->moderation_status !== ContentModerationStatus::APPROVED) {
            return $this->isAuthor($thread, $user);
        }

        $visibility = $this->forumOf($thread)->visibility;

        if ($visibility === ForumVisibility::STAFF_AUTHOR_ONLY) {
            return $this->isAuthor($thread, $user);
        }

        if ($visibility === ForumVisibility::PAID_OR_AUTHOR) {
            if ($user === null) {
                return false;
            }

            return $this->hasPaidForumAccess($this->forumOf($thread), $user) || $this->isAuthor($thread, $user);
        }

        return true;
    }

    /**
     * Can the viewer start a thread in the forum (and, for most policies, reply in it)?
     *
     * @param  list<int>|null  $authoredForumIds
     */
    public function canPostInForum(Forum $forum, ?Authenticatable $user, ?array $authoredForumIds = null): bool
    {
        if (! $forum->is_active || $forum->is_read_only) {
            return false;
        }

        if ($this->actsAsStaff($user)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return match ($forum->posting_policy) {
            ForumPostingPolicy::PUBLIC, ForumPostingPolicy::REGISTERED => true,
            ForumPostingPolicy::STAFF_ONLY => false,
            ForumPostingPolicy::STAFF_AUTHOR_ONLY => in_array((int) $forum->getKey(), $authoredForumIds ?? $this->authoredForumIds($user), true)
                || ($forum->show_when_empty && $this->isForumEmpty($forum)),
            ForumPostingPolicy::ENTITLEMENT_ONLY => $this->holdsEntitlement($user, $forum->reply_entitlement_scope),
        };
    }

    /**
     * Can the viewer reply to this thread? They must be able to post in its forum; beyond that
     * a thread's own author can always reply, and anyone else needs the forum's reply
     * entitlement when it names one.
     *
     * @param  list<int>|null  $authoredForumIds
     */
    public function canReplyToThread(Thread $thread, ?Authenticatable $user, ?array $authoredForumIds = null): bool
    {
        $forum = $this->forumOf($thread);

        if (! $this->canPostInForum($forum, $user, $authoredForumIds)) {
            return false;
        }

        if ($this->actsAsStaff($user)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->isAuthor($thread, $user)) {
            return true;
        }

        $replyEntitlement = trim((string) $forum->reply_entitlement_scope);

        return $replyEntitlement === '' || $this->holdsEntitlement($user, $replyEntitlement);
    }

    /**
     * In this forum the viewer sees only their own threads, not everyone's: `staff_author_only`
     * forums, and `paid_or_author` forums for a viewer without paid access.
     */
    public function seesOnlyOwnThreads(Forum $forum, ?Authenticatable $user): bool
    {
        if ($this->actsAsStaff($user)) {
            return false;
        }

        return match ($forum->visibility) {
            ForumVisibility::STAFF_AUTHOR_ONLY => true,
            ForumVisibility::PAID_OR_AUTHOR => $user === null || ! $this->hasPaidForumAccess($forum, $user),
            default => false,
        };
    }

    /**
     * Every active forum in an active group the viewer can see, split into those they see in
     * full and those where they see only their own threads.
     */
    public function forumScopeFor(?Authenticatable $user): ForumViewerScope
    {
        if ($this->actsAsStaff($user)) {
            return new ForumViewerScope(true, [], []);
        }

        $forumModel = Models::class('forum');
        $groupModel = Models::class('forum_group');
        $authored = $this->authoredForumIds($user);
        $full = [];
        $own = [];

        $activeGroupIds = $groupModel::query()->where('is_active', true)->pluck('id')->all();

        /** @var iterable<Forum> $forums */
        $forums = $forumModel::query()->where('is_active', true)->whereIn('forum_group_id', $activeGroupIds)->orderBy('id')->get();

        foreach ($forums as $forum) {
            if (! $this->canViewForum($forum, $user, $authored)) {
                continue;
            }

            if ($this->seesOnlyOwnThreads($forum, $user)) {
                $own[] = (int) $forum->getKey();
            } else {
                $full[] = (int) $forum->getKey();
            }
        }

        return new ForumViewerScope(false, $full, $own);
    }

    /**
     * Narrow a thread query to what the viewer may see of moderation: approved threads, and the
     * viewer's own whatever their status. (Staff who see everything do not call this.)
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleThreads(Builder $query, ?Authenticatable $user): Builder
    {
        return $this->visibleByStatus($query, $user, 'author_id');
    }

    /**
     * The same for posts.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisiblePosts(Builder $query, ?Authenticatable $user): Builder
    {
        return $this->visibleByStatus($query, $user, 'author_id');
    }

    /**
     * The forums the user has started a thread in.
     *
     * @return list<int>
     */
    public function authoredForumIds(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $key = (string) $user->getAuthIdentifier();

        if (! isset($this->authoredForumIds[$key])) {
            $thread = Models::class('thread');

            $this->authoredForumIds[$key] = $thread::query()
                ->where('author_id', $user->getAuthIdentifier())
                ->distinct()
                ->pluck('forum_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $this->authoredForumIds[$key];
    }

    /**
     * Forget what was remembered about which forums users authored in, after threads change.
     */
    public function flush(): void
    {
        $this->authoredForumIds = [];
    }

    /**
     * Holds the entitlement (an empty key is never held).
     */
    private function holdsEntitlement(?Authenticatable $user, ?string $entitlement): bool
    {
        $entitlement = trim((string) $entitlement);

        return $user !== null && $entitlement !== '' && $this->authorization->allows($user, $entitlement);
    }

    /**
     * "Paid" access: the forum's required entitlement when it names one, otherwise whatever the
     * host's PaidAccessCheck says (an active membership, for instance).
     */
    private function hasPaidForumAccess(Forum $forum, ?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $required = trim((string) $forum->required_entitlement_scope);

        if ($required !== '') {
            return $this->holdsEntitlement($user, $required);
        }

        return $this->paid->hasPaidAccess($user);
    }

    private function isAuthor(Thread $thread, ?Authenticatable $user): bool
    {
        return $user !== null && (string) $thread->author_id === (string) $user->getAuthIdentifier();
    }

    /**
     * Uses the counts already on the model when it has them (a `threads_count` from withCount,
     * or the stored `thread_count`; a forum loaded with only some columns may have neither),
     * and asks the database otherwise.
     */
    private function isForumEmpty(Forum $forum): bool
    {
        $attributes = $forum->getAttributes();

        foreach (['threads_count', 'thread_count'] as $column) {
            if (isset($attributes[$column]) && is_numeric($attributes[$column])) {
                return (int) $attributes[$column] === 0;
            }
        }

        $thread = Models::class('thread');

        return ! $thread::query()->where('forum_id', $forum->getKey())->exists();
    }

    private function forumOf(Thread $thread): Forum
    {
        $forum = $thread->forum;

        assert($forum instanceof Forum);

        return $forum;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function visibleByStatus(Builder $query, ?Authenticatable $user, string $authorColumn): Builder
    {
        $model = $query->getModel();

        return $query->where(function (Builder $visible) use ($model, $user, $authorColumn): void {
            $visible->where($model->qualifyColumn('moderation_status'), ContentModerationStatus::APPROVED->value);

            if ($user !== null) {
                $visible->orWhere($model->qualifyColumn($authorColumn), $user->getAuthIdentifier());
            }
        });
    }
}
