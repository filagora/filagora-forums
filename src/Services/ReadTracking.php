<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Carbon\CarbonInterface;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each person has read. A thread is unread for a person when its latest activity (its last
 * post, or its creation) is later than the latest of their baseline, when they last read the
 * thread and when they last read its forum, or when they flagged it unread by hand, which beats
 * all of those until they open it again. The baseline is a column of your user model (see
 * `read_tracking.baseline_attribute`); everything before it counts as read.
 *
 * Which threads a person may see is not decided here: pass a query that is already narrowed
 * to them (`ForumAccess::scopeVisibleThreads()` and the forum scope).
 */
class ReadTracking
{
    public function __construct(private readonly ForumAccess $access) {}

    /**
     * The moment before which everything counts as read for this person, or null for none.
     */
    public function baseline(Authenticatable $user): ?CarbonInterface
    {
        $attribute = config('filagora-forums.read_tracking.baseline_attribute');

        if (! is_string($attribute) || $attribute === '') {
            return null;
        }

        $value = $user instanceof Model ? $user->getAttribute($attribute) : ($user->{$attribute} ?? null);

        return $value === null || $value === '' ? null : Carbon::parse($value);
    }

    /**
     * The earliest activity an unread list will consider: the baseline, but never further back
     * than `unread_max_lookback_days`, because a baseline carried over from an old forum can be
     * years old and would otherwise match every thread ever posted.
     */
    public function floor(Authenticatable $user): CarbonInterface
    {
        $earliest = now()->subDays(max(1, (int) config('filagora-forums.read_tracking.unread_max_lookback_days', 365)));
        $baseline = $this->baseline($user);

        return $baseline !== null && $baseline->greaterThan($earliest) ? $baseline : $earliest;
    }

    /**
     * They opened the thread: it is read as of now, and a hand-made unread flag is cleared.
     */
    public function markThreadRead(Authenticatable $user, Thread $thread): void
    {
        $this->record($user, $thread, false);
    }

    /**
     * Flag the thread unread by hand, so it shows as unread until the person opens it again.
     */
    public function markThreadUnread(Authenticatable $user, Thread $thread): void
    {
        $this->record($user, $thread, true);
    }

    /**
     * Mark these forums read as of now and clear the hand-made unread flags on their threads.
     *
     * @param  list<int|string>  $forumIds
     * @return int How many forums were marked.
     */
    public function markForumsRead(Authenticatable $user, array $forumIds): int
    {
        $forumIds = array_values(array_unique($forumIds));

        if ($forumIds === []) {
            return 0;
        }

        $now = now();
        $userId = $user->getAuthIdentifier();

        DB::transaction(function () use ($userId, $forumIds, $now): void {
            Models::class('forum_read')::query()->upsert(
                array_map(static fn (int|string $forumId): array => ['user_id' => $userId, 'forum_id' => $forumId, 'last_read_at' => $now, 'created_at' => $now, 'updated_at' => $now], $forumIds),
                ['user_id', 'forum_id'],
                ['last_read_at', 'updated_at'],
            );

            Models::class('thread_read')::query()
                ->where('user_id', $userId)
                ->where('is_unread_override', true)
                ->whereHas('thread', fn (Builder $thread) => $thread->whereIn('forum_id', $forumIds))
                ->update(['is_unread_override' => false]);
        });

        return count($forumIds);
    }

    /**
     * "Mark all read": every forum the person can see.
     *
     * @return int How many forums were marked.
     */
    public function markAllRead(Authenticatable $user): int
    {
        $scope = $this->access->forumScopeFor($user);

        $forumIds = $scope->unrestricted
            ? Models::class('forum')::query()->where('is_active', true)->pluck('id')->all()
            : [...$scope->fullForumIds, ...$scope->ownThreadsForumIds];

        return $this->markForumsRead($user, $forumIds);
    }

    public function isUnread(Authenticatable $user, Thread $thread): bool
    {
        return in_array($thread->getKey(), $this->unreadAmong($user, [$thread]), true);
    }

    /**
     * Which of these threads are unread, for a whole page in two queries.
     *
     * @param  iterable<Thread>  $threads
     * @return list<int|string> The keys of the unread ones.
     */
    public function unreadAmong(Authenticatable $user, iterable $threads): array
    {
        $threads = collect($threads)->values();

        if ($threads->isEmpty()) {
            return [];
        }

        $userId = $user->getAuthIdentifier();
        $baseline = $this->baseline($user);

        $threadReads = Models::class('thread_read')::query()
            ->where('user_id', $userId)
            ->whereIn('thread_id', $threads->map(fn (Thread $thread) => $thread->getKey())->all())
            ->get(['thread_id', 'last_read_at', 'is_unread_override'])
            ->keyBy(fn (Model $read): string => (string) $read->getAttribute('thread_id'));

        $forumReads = Models::class('forum_read')::query()
            ->where('user_id', $userId)
            ->whereIn('forum_id', $threads->pluck('forum_id')->unique()->values()->all())
            ->pluck('last_read_at', 'forum_id');

        $unread = [];

        foreach ($threads as $thread) {
            $threadRead = $threadReads->get((string) $thread->getKey());
            $readAt = $baseline;

            foreach ([$threadRead?->getAttribute('last_read_at'), $forumReads->get($thread->forum_id)] as $candidate) {
                if ($candidate !== null && ($readAt === null || Carbon::parse($candidate)->gt($readAt))) {
                    $readAt = Carbon::parse($candidate);
                }
            }

            $activity = $this->activityAt($thread);

            if ((bool) $threadRead?->getAttribute('is_unread_override') || $readAt === null || $activity->gt($readAt)) {
                $unread[] = $thread->getKey();
            }
        }

        return $unread;
    }

    /**
     * Threads that are unread for this person, to page through ("what's new"). Pass a query
     * already limited to what they may see. Only activity since `floor()` is considered, and
     * threads flagged unread by hand are included whatever their age. The read times are checked
     * with subqueries on their unique keys rather than joins, so the query you pass in keeps
     * its columns and can use unqualified column names. The override ids are fetched on their
     * own and only added to the query when there are some: an OR spanning a joined table stops
     * the database using the date index and scans every thread.
     *
     * @param  Builder<Model>|null  $threads
     * @return Builder<Model>
     */
    public function unreadThreads(Authenticatable $user, ?Builder $threads = null): Builder
    {
        $query = $threads ?? Models::class('thread')::query();
        $thread = $query->getModel()->getTable();
        $threadReads = (new (Models::class('thread_read')))->getTable();
        $forumReads = (new (Models::class('forum_read')))->getTable();
        $userId = $user->getAuthIdentifier();
        $floor = $this->floor($user);

        $overrides = Models::class('thread_read')::query()
            ->where('user_id', $userId)
            ->where('is_unread_override', true)
            ->pluck('thread_id')
            ->all();

        $unreadSince = function (Builder $inner) use ($thread, $threadReads, $forumReads, $userId, $floor): void {
            $inner->where("{$thread}.last_activity_at", '>', $floor)
                ->whereNotExists(fn ($read) => $read->selectRaw('1')->from($threadReads)
                    ->whereColumn("{$threadReads}.thread_id", "{$thread}.id")->where("{$threadReads}.user_id", $userId)
                    ->whereColumn("{$threadReads}.last_read_at", '>=', "{$thread}.last_activity_at"))
                ->whereNotExists(fn ($read) => $read->selectRaw('1')->from($forumReads)
                    ->whereColumn("{$forumReads}.forum_id", "{$thread}.forum_id")->where("{$forumReads}.user_id", $userId)
                    ->whereColumn("{$forumReads}.last_read_at", '>=', "{$thread}.last_activity_at"));
        };

        return $query->where(function (Builder $unread) use ($overrides, $thread, $unreadSince): void {
            if ($overrides === []) {
                $unreadSince($unread);

                return;
            }

            $unread->whereIn("{$thread}.id", $overrides)->orWhere(fn (Builder $recent) => $unreadSince($recent));
        });
    }

    private function record(Authenticatable $user, Thread $thread, bool $override): void
    {
        Models::class('thread_read')::query()->updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'thread_id' => $thread->getKey()],
            ['last_read_at' => now(), 'is_unread_override' => $override],
        );
    }

    private function activityAt(Thread $thread): CarbonInterface
    {
        $created = $thread->created_at ?? now();

        return $thread->last_post_at !== null && $thread->last_post_at->gt($created) ? $thread->last_post_at : $created;
    }
}
