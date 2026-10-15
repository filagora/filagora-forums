<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Enums\ReactionRefusal;
use Filagora\Forums\Exceptions\ReactionRefused;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Reaction;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Reactions to posts. A person reacts to a post they can see, once per kind; reacting again takes
 * it back (`toggle`). Posts that are deleted or awaiting approval take none. The kinds on offer
 * are `reactions.types` in the config (just `like` by default); the count and "did I" helpers
 * answer for many posts in one query so a thread page does not ask post by post.
 */
class Reactions
{
    public function __construct(private readonly ForumAccess $access) {}

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) config('filagora-forums.reactions.types', ['like'])), static fn (string $type): bool => $type !== '')));
    }

    /**
     * Add the reaction, or take it back if the person already made it. True when it is now there.
     */
    public function toggle(Authenticatable $user, Post $post, string $type = 'like'): bool
    {
        if (! in_array($type, $this->types(), true)) {
            throw new ReactionRefused(ReactionRefusal::UNKNOWN_TYPE);
        }

        $thread = $post->thread;
        assert($thread instanceof Thread);
        $forum = $thread->forum;
        assert($forum instanceof Forum);

        if (! $this->access->canViewForum($forum, $user) || ! $this->access->canAccessThread($thread, $user)) {
            throw new ReactionRefused(ReactionRefusal::NOT_VISIBLE);
        }

        if ($post->is_deleted) {
            throw new ReactionRefused(ReactionRefusal::POST_DELETED);
        }

        if (! $post->isApproved()) {
            throw new ReactionRefused(ReactionRefusal::POST_NOT_APPROVED);
        }

        $existing = $this->reactions()->where('post_id', $post->getKey())->where('user_id', $user->getAuthIdentifier())->where('type', $type)->first();

        if ($existing instanceof Reaction) {
            $existing->delete();

            return false;
        }

        try {
            $this->reactions()->create(['post_id' => $post->getKey(), 'user_id' => $user->getAuthIdentifier(), 'type' => $type]);
        } catch (UniqueConstraintViolationException) {
            // A double click made it a moment ago; the reaction is there.
        }

        return true;
    }

    /**
     * How many of each kind of reaction each post has.
     *
     * @param  iterable<int>  $postIds
     * @return array<int, array<string, int>> post id => kind => count (posts with none are absent)
     */
    public function counts(iterable $postIds): array
    {
        $ids = collect($postIds)->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids === []) {
            return [];
        }

        $counts = [];

        $rows = $this->reactions()->whereIn('post_id', $ids)->selectRaw('post_id, type, count(*) as total')->groupBy('post_id', 'type')->get();

        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('post_id')][(string) $row->getAttribute('type')] = (int) $row->getAttribute('total');
        }

        return $counts;
    }

    /**
     * The kinds of reaction a person has made to each of the posts.
     *
     * @param  iterable<int>  $postIds
     * @return array<int, list<string>> post id => kinds
     */
    public function madeBy(?Authenticatable $user, iterable $postIds): array
    {
        $ids = collect($postIds)->map(fn ($id): int => (int) $id)->values()->all();

        if ($user === null || $ids === []) {
            return [];
        }

        $made = [];

        foreach ($this->reactions()->where('user_id', $user->getAuthIdentifier())->whereIn('post_id', $ids)->orderBy('id')->get(['post_id', 'type']) as $row) {
            $made[(int) $row->getAttribute('post_id')][] = (string) $row->getAttribute('type');
        }

        return $made;
    }

    /**
     * @return Builder<Reaction>
     */
    private function reactions(): Builder
    {
        /** @var Builder<Reaction> */
        return Models::class('reaction')::query();
    }
}
