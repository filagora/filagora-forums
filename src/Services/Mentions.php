<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Core\Support\UserModel;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\UsersMentioned;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `@name` in a post. The names are matched, without regard to case, against the user attribute
 * `mentions.user_attribute` (`username` by default). Code (fenced or inline) and the blocks the
 * Quote button inserts (a `> **Author:**` line and the `>` lines after it) are not scanned, so
 * quoting a post does not replay its mentions; a hand-typed `>` quote is scanned.
 *
 * Nobody is told twice about the same post however often it is edited, the author is never told
 * about their own post, only people who can see the thread are told, and at most
 * `mentions.max_per_post` people are told about one post, ever: the cap counts everyone already
 * told, so editing in fresh names cannot cycle through recipients. The post row is locked while
 * this runs so two edits at once cannot both read the same remaining budget.
 */
class Mentions
{
    /** The header the Quote button writes before a quoted body. */
    private const QUOTE_HEADER = '/^>\s*\*\*.*\*\*\s*$/';

    public function __construct(private readonly ForumAccess $access) {}

    /**
     * The names mentioned in a body, in order of first appearance, one per person.
     *
     * @return list<string>
     */
    public function usernames(string $body): array
    {
        $scannable = $this->withoutQuotesAndCode($body);

        if (preg_match_all('/(?<![\w@])@([A-Za-z0-9._-]{2,30})/u', $scannable, $matches) === 0) {
            return [];
        }

        return collect($matches[1])
            ->map(static fn (string $name): string => rtrim($name, '.-'))
            ->filter(static fn (string $name): bool => mb_strlen($name) >= 2)
            ->unique(static fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
    }

    /**
     * Record and announce the mentions of a visible post, and return the people newly told.
     *
     * @return Collection<int, Authenticatable>
     */
    public function sync(Post $post): Collection
    {
        $thread = $post->thread;

        if (! $thread instanceof Thread || ! $this->isVisible($post, $thread)) {
            return collect();
        }

        $names = $this->usernames((string) $post->body);

        if ($names === []) {
            return collect();
        }

        $newlyTold = DB::transaction(fn (): Collection => $this->record($post, $thread, $names));

        if ($newlyTold->isNotEmpty()) {
            UsersMentioned::dispatch($post, $newlyTold->all());
        }

        return $newlyTold;
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Authenticatable>
     */
    private function record(Post $post, Thread $thread, array $names): Collection
    {
        $mentions = Models::class('post_mention');

        // Serialise concurrent calls for the same post.
        Models::class('post')::query()->whereKey($post->getKey())->lockForUpdate()->first();

        $remaining = max(0, (int) config('filagora-forums.mentions.max_per_post', 10)) - $mentions::query()->where('post_id', $post->getKey())->count();

        if ($remaining <= 0) {
            return collect();
        }

        // Enough headroom that candidates who cannot see the thread or were told before still
        // leave the remaining slots to fill, in the order the names appear.
        $candidates = array_slice($names, 0, $remaining * 3);
        $users = $this->usersNamed($candidates, $post);
        $told = $mentions::query()->where('post_id', $post->getKey())->pluck('user_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $forum = $thread->forum;

        $new = collect($candidates)
            ->map(static fn (string $name): ?Model => $users->get(mb_strtolower($name)))
            ->filter(static fn (?Model $user): bool => $user !== null)
            ->unique(static fn (Model $user): string => (string) $user->getKey())
            ->reject(static fn (Model $user): bool => in_array((string) $user->getKey(), $told, true))
            ->filter(fn (Model $user): bool => $forum instanceof Forum
                && $user instanceof Authenticatable
                && $this->access->canViewForum($forum, $user)
                && $this->access->canAccessThread($thread, $user))
            ->take($remaining)
            ->values();

        $now = now();

        foreach ($new as $user) {
            $mentions::query()->updateOrCreate(['post_id' => $post->getKey(), 'user_id' => $user->getKey()], ['notified_at' => $now]);
        }

        /** @var Collection<int, Authenticatable> $told */
        $told = $new;

        return $told;
    }

    /**
     * @param  list<string>  $names
     * @return Collection<string, Model> keyed by lower-case name
     */
    private function usersNamed(array $names, Post $post): Collection
    {
        $attribute = (string) config('filagora-forums.mentions.user_attribute', 'username');
        $query = UserModel::class()::query();
        $column = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($attribute));
        $lowered = array_map(static fn (string $name): string => mb_strtolower($name), $names);

        return $query
            ->whereRaw("LOWER({$column}) in (".implode(',', array_fill(0, count($lowered), '?')).')', $lowered)
            ->where($query->getModel()->qualifyColumn($query->getModel()->getKeyName()), '!=', $post->author_id)
            ->get()
            ->keyBy(static fn (Model $user): string => mb_strtolower((string) $user->getAttribute($attribute)));
    }

    private function isVisible(Post $post, Thread $thread): bool
    {
        return $post->moderation_status === ContentModerationStatus::APPROVED
            && ! $post->is_deleted
            && $thread->moderation_status === ContentModerationStatus::APPROVED;
    }

    private function withoutQuotesAndCode(string $body): string
    {
        $noFenced = preg_replace('/```.*?```/s', ' ', $body) ?? $body;
        $noInline = preg_replace('/`[^`\n]*`/', ' ', $noFenced) ?? $noFenced;

        $lines = preg_split('/\r\n|\r|\n/', $noInline) ?: [];
        $kept = [];
        $total = count($lines);

        for ($index = 0; $index < $total; $index++) {
            if (preg_match(self::QUOTE_HEADER, trim($lines[$index])) === 1) {
                do {
                    $index++;
                } while ($index < $total && str_starts_with(ltrim($lines[$index]), '>'));
                $index--;

                continue;
            }

            $kept[] = $lines[$index];
        }

        return implode("\n", $kept);
    }
}
