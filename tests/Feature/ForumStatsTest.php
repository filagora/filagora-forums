<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Events\ForumStatsChanged;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ForumStats;
use Filagora\Forums\Services\ThreadStats;
use Filagora\Forums\Support\StatsSyncScope;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function statsForum(string $slug = 'general'): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(['forum_group_id' => $group->id, 'name' => ucfirst($slug), 'slug' => $slug])->refresh();
}

function statsThread(Forum $forum, TestUser $author, array $attributes = []): Thread
{
    return Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't-'.uniqid()], $attributes))->refresh();
}

function statsPost(Thread $thread, TestUser $author, array $attributes = []): Post
{
    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Body'], $attributes))->refresh();
}

/**
 * Set a post's created_at without going through mass assignment.
 */
function statsPostAt(Post $post, string $at): Post
{
    DB::table(Tables::name('posts'))->where('id', $post->id)->update(['created_at' => $at]);

    return $post->refresh();
}

/**
 * The forum's counters, read fresh from the database.
 *
 * @return array{threads: int, posts: int, last_post: int|null, last_thread: int|null, last_author: int|null}
 */
function counters(Forum $forum): array
{
    $row = DB::table(Tables::name('forums'))->where('id', $forum->id)->first();

    return [
        'threads' => (int) $row->thread_count,
        'posts' => (int) $row->post_count,
        'last_post' => $row->last_post_id !== null ? (int) $row->last_post_id : null,
        'last_thread' => $row->last_post_thread_id !== null ? (int) $row->last_post_thread_id : null,
        'last_author' => $row->last_post_author_id !== null ? (int) $row->last_post_author_id : null,
    ];
}

/**
 * Wreck a forum's counters behind the observers' back, to prove the recompute repairs them.
 */
function corrupt(Forum $forum): void
{
    DB::table(Tables::name('forums'))->where('id', $forum->id)->update(['thread_count' => 99, 'post_count' => 99, 'last_post_id' => 12345, 'last_post_thread_id' => 12345, 'last_post_author_id' => 12345]);
}

// -------------------------------------------------------------------- incremental

it('counts approved threads and posts as they are created, and tracks the last post', function () {
    $forum = statsForum();
    $author = TestUser::make('Author');
    $other = TestUser::make('Other');

    expect(counters($forum))->toBe(['threads' => 0, 'posts' => 0, 'last_post' => null, 'last_thread' => null, 'last_author' => null]);

    $thread = statsThread($forum, $author);

    expect(counters($forum)['threads'])->toBe(1)->and(counters($forum)['posts'])->toBe(0);

    $first = statsPost($thread, $author);
    $second = statsPost($thread, $other);

    expect(counters($forum))->toBe(['threads' => 1, 'posts' => 2, 'last_post' => $second->id, 'last_thread' => $thread->id, 'last_author' => $other->id])
        ->and($first->id)->not->toBe($second->id);
});

it('does not count posts that are not approved, or threads that are not', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);
    $pendingThread = statsThread($forum, $author, ['moderation_status' => ContentModerationStatus::PENDING]);

    statsPost($thread, $author, ['moderation_status' => ContentModerationStatus::PENDING]);
    statsPost($thread, $author, ['moderation_status' => ContentModerationStatus::REJECTED]);
    statsPost($pendingThread, $author);

    expect(counters($forum))->toBe(['threads' => 1, 'posts' => 0, 'last_post' => null, 'last_thread' => null, 'last_author' => null]);
});

it('counts a thread\'s posts once the thread is approved', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author, ['moderation_status' => ContentModerationStatus::PENDING]);
    $post = statsPost($thread, $author);

    expect(counters($forum)['posts'])->toBe(0);

    $thread->update(['moderation_status' => ContentModerationStatus::APPROVED]);

    expect(counters($forum))->toMatchArray(['threads' => 1, 'posts' => 1, 'last_post' => $post->id]);

    $thread->update(['moderation_status' => ContentModerationStatus::REJECTED]);

    expect(counters($forum))->toMatchArray(['threads' => 0, 'posts' => 0, 'last_post' => null]);
});

it('follows a post through approval, rejection and deletion', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);
    $kept = statsPost($thread, $author);
    $post = statsPost($thread, $author, ['moderation_status' => ContentModerationStatus::PENDING]);

    expect(counters($forum))->toMatchArray(['posts' => 1, 'last_post' => $kept->id]);

    $post->update(['moderation_status' => ContentModerationStatus::APPROVED]);
    expect(counters($forum))->toMatchArray(['posts' => 2, 'last_post' => $post->id]);

    $post->update(['moderation_status' => ContentModerationStatus::REJECTED]);
    expect(counters($forum))->toMatchArray(['posts' => 1, 'last_post' => $kept->id]);

    $post->update(['moderation_status' => ContentModerationStatus::APPROVED]);
    $post->delete();
    expect(counters($forum))->toMatchArray(['posts' => 1, 'last_post' => $kept->id]);

    $kept->delete();
    expect(counters($forum))->toMatchArray(['posts' => 0, 'last_post' => null]);
});

it('moves a post\'s count with it, between threads of one forum and of two', function () {
    $a = statsForum('a');
    $b = statsForum('b');
    $author = TestUser::make();
    $threadA1 = statsThread($a, $author);
    $threadA2 = statsThread($a, $author);
    $threadB = statsThread($b, $author);
    $post = statsPost($threadA1, $author);

    expect(counters($a)['posts'])->toBe(1)->and(counters($b)['posts'])->toBe(0);

    $post->update(['thread_id' => $threadA2->id]);
    expect(counters($a))->toMatchArray(['posts' => 1, 'last_post' => $post->id])
        ->and(counters($b)['posts'])->toBe(0);

    $post->update(['thread_id' => $threadB->id]);
    expect(counters($a))->toMatchArray(['posts' => 0, 'last_post' => null])
        ->and(counters($b))->toMatchArray(['posts' => 1, 'last_post' => $post->id, 'last_thread' => $threadB->id]);
});

it('recounts both forums when a thread moves between them', function () {
    $a = statsForum('a');
    $b = statsForum('b');
    $author = TestUser::make();
    $thread = statsThread($a, $author);
    statsPost($thread, $author);
    statsPost($thread, $author);

    expect(counters($a))->toMatchArray(['threads' => 1, 'posts' => 2]);

    $thread->update(['forum_id' => $b->id]);

    expect(counters($a))->toMatchArray(['threads' => 0, 'posts' => 0, 'last_post' => null])
        ->and(counters($b))->toMatchArray(['threads' => 1, 'posts' => 2]);

    $thread->delete();

    expect(counters($b))->toMatchArray(['threads' => 0, 'posts' => 0]);
});

it('ignores a plain edit, which changes nothing the counters depend on', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $post = statsPost(statsThread($forum, $author), $author);

    Event::fake([ForumStatsChanged::class]);

    $post->update(['body' => 'Edited', 'edited_at' => now(), 'edited_by_id' => $author->id]);

    Event::assertNotDispatched(ForumStatsChanged::class);
    expect(counters($forum)['posts'])->toBe(1);
});

it('announces which forums\' counters changed', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);

    Event::fake([ForumStatsChanged::class]);

    statsPost($thread, $author);

    Event::assertDispatched(ForumStatsChanged::class, fn (ForumStatsChanged $event) => $event->forumIds === [$forum->id]);
});

// ------------------------------------------------------------------- suppression

it('lets a multi-step operation suppress the observers and do one update of its own', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);

    StatsSyncScope::withoutPostSync(function () use ($thread, $author) {
        statsPost($thread, $author);
        statsPost($thread, $author);
    });

    expect(counters($forum)['posts'])->toBe(0);

    app(ForumStats::class)->syncForForumId($forum->id);

    expect(counters($forum)['posts'])->toBe(2);

    StatsSyncScope::withoutThreadSync(function () use ($forum, $author) {
        statsThread($forum, $author);

        expect(StatsSyncScope::suppressesThreadSync())->toBeTrue()->and(StatsSyncScope::suppressesPostSync())->toBeFalse();
    });

    expect(counters($forum)['threads'])->toBe(1);
});

it('nests the suppression and lifts it even when the operation throws', function () {
    try {
        StatsSyncScope::withoutPostSync(function () {
            StatsSyncScope::withoutPostSync(fn () => expect(StatsSyncScope::suppressesPostSync())->toBeTrue());

            expect(StatsSyncScope::suppressesPostSync())->toBeTrue();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(StatsSyncScope::suppressesPostSync())->toBeFalse()->and(StatsSyncScope::suppressesThreadSync())->toBeFalse();
});

// ---------------------------------------------------------------- full recompute

it('rebuilds a forum\'s counters from its threads and posts', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);
    $last = statsPost($thread, $author);
    statsPost($thread, $author, ['moderation_status' => ContentModerationStatus::PENDING]);
    $other = statsThread($forum, $author, ['moderation_status' => ContentModerationStatus::PENDING]);
    statsPost($other, $author);
    $thread->update(['last_post_at' => $last->created_at]);

    corrupt($forum);
    expect(counters($forum)['posts'])->toBe(99);

    app(ForumStats::class)->syncForForumIds([$forum->id, 0, -3, $forum->id]);

    expect(counters($forum))->toBe(['threads' => 1, 'posts' => 1, 'last_post' => $last->id, 'last_thread' => $thread->id, 'last_author' => $author->id]);
});

it('zeroes a forum that has nothing to count, and ignores ids that do not exist', function () {
    $forum = statsForum();
    corrupt($forum);

    app(ForumStats::class)->syncForForumIds([$forum->id, 987654]);

    expect(counters($forum))->toBe(['threads' => 0, 'posts' => 0, 'last_post' => null, 'last_thread' => null, 'last_author' => null]);

    app(ForumStats::class)->syncForForumIds([]);
    app(ForumStats::class)->syncForForumIds([987654]);
});

it('takes the forum\'s last post from the thread with the latest activity', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $older = statsThread($forum, $author);
    $newer = statsThread($forum, $author);
    $olderPost = statsPostAt(statsPost($older, $author), '2026-01-01 10:00:00');
    $newerFirst = statsPostAt(statsPost($newer, $author), '2026-02-01 10:00:00');
    $newerLast = statsPostAt(statsPost($newer, $author), '2026-02-02 10:00:00');
    $older->update(['last_post_at' => $olderPost->created_at]);
    $newer->update(['last_post_at' => $newerLast->created_at]);

    app(ForumStats::class)->syncForForumId($forum->id);

    expect(counters($forum))->toMatchArray(['last_post' => $newerLast->id, 'last_thread' => $newer->id])
        ->and($newerFirst->id)->toBeLessThan($newerLast->id);
});

it('keeps counting a soft-deleted post in the forum\'s post count, as the forum it came from did', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);
    $post = statsPost($thread, $author);
    $post->update(['is_deleted' => true, 'deleted_at' => now()]);

    app(ForumStats::class)->syncForForumId($forum->id);

    // Thread reply counts do exclude deleted posts; see ThreadStats.
    expect(counters($forum)['posts'])->toBe(1);
});

it('adjusts the counters directly for a newly approved post', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $thread = statsThread($forum, $author);
    $post = statsPost($thread, $author, ['moderation_status' => ContentModerationStatus::PENDING]);

    app(ForumStats::class)->incrementForApprovedPost($forum, $post, incrementThreadCount: true);

    expect(counters($forum))->toMatchArray(['threads' => 2, 'posts' => 1, 'last_post' => $post->id]);

    app(ForumStats::class)->applyPostCountDelta($forum->id, 0);
    app(ForumStats::class)->applyPostCountDelta(0, 5);
    app(ForumStats::class)->setLastPostFromPost(0, $post);
    app(ForumStats::class)->refreshLastPostForForumId(0);

    expect(counters($forum)['posts'])->toBe(1);
});

// ------------------------------------------------------------------ thread stats

it('rebuilds a thread\'s reply count and last post from its approved, undeleted posts', function () {
    $forum = statsForum();
    $author = TestUser::make('Author');
    $replier = TestUser::make('Replier');
    $thread = statsThread($forum, $author);
    $empty = statsThread($forum, $author);
    statsPostAt(statsPost($thread, $author), '2026-01-01 10:00:00');
    $reply = statsPostAt(statsPost($thread, $replier), '2026-01-02 10:00:00');
    statsPostAt(statsPost($thread, $author, ['moderation_status' => ContentModerationStatus::PENDING]), '2026-01-03 10:00:00');
    statsPostAt(statsPost($thread, $author, ['is_deleted' => true]), '2026-01-04 10:00:00');

    DB::table(Tables::name('threads'))->whereIn('id', [$thread->id, $empty->id])->update(['reply_count' => 50, 'last_post_author_id' => $author->id, 'last_post_at' => '2030-01-01 00:00:00']);

    $progress = [];
    $result = app(ThreadStats::class)->syncForThreadIds([$thread->id, $empty->id, 0, $thread->id], function (int ...$args) use (&$progress) {
        $progress[] = $args;
    });

    expect($result['thread_ids'])->toBe(2)->and($result['threads_updated'])->toBe(2)->and($result['runtime_seconds'])->toBeFloat()
        ->and($progress)->toBe([[1, 1, 2, 2]])
        ->and($thread->refresh()->reply_count)->toBe(1)
        ->and($thread->last_post_author_id)->toBe($replier->id)
        ->and($thread->last_post_at->toDateTimeString())->toBe($reply->created_at->toDateTimeString())
        ->and($empty->refresh()->reply_count)->toBe(0)
        ->and($empty->last_post_author_id)->toBeNull()
        ->and($empty->last_post_at)->toBeNull();

    expect(app(ThreadStats::class)->syncForThreadIds([]))->toBe(['thread_ids' => 0, 'threads_updated' => 0, 'runtime_seconds' => 0.0]);
});

it('breaks a tie on the last post by taking the later post', function () {
    $forum = statsForum();
    $first = TestUser::make('First');
    $second = TestUser::make('Second');
    $thread = statsThread($forum, $first);
    statsPostAt(statsPost($thread, $first), '2026-05-05 12:00:00');
    $later = statsPostAt(statsPost($thread, $second), '2026-05-05 12:00:00');

    app(ThreadStats::class)->syncForThreadIds([$thread->id]);

    expect($thread->refresh()->last_post_author_id)->toBe($second->id)->and($later->id)->toBeGreaterThan(0);
});

// ----------------------------------------------------------------------- commands

it('rebuilds every forum, or only the ones asked for', function () {
    $a = statsForum('a');
    $b = statsForum('b');
    $author = TestUser::make();
    statsPost(statsThread($a, $author), $author);
    statsPost(statsThread($b, $author), $author);
    corrupt($a);
    corrupt($b);

    $this->artisan('filagora:forums:sync-stats', ['--forum-id' => [$a->id]])->expectsOutputToContain('Forum counters synced.')->assertExitCode(0);

    expect(counters($a)['posts'])->toBe(1)->and(counters($b)['posts'])->toBe(99);

    $this->artisan('filagora:forums:sync-stats')->assertExitCode(0);

    expect(counters($b)['posts'])->toBe(1);
});

it('says so when there is nothing to sync', function () {
    $this->artisan('filagora:forums:sync-stats')->expectsOutputToContain('No forums found to sync.')->assertExitCode(0);
    $this->artisan('filagora:forums:sync-stats', ['--forum-id' => [999]])->expectsOutputToContain('No forums found to sync.')->assertExitCode(0);
    $this->artisan('filagora:forums:sync-thread-stats')->expectsOutputToContain('No threads found to sync.')->assertExitCode(0);
});

it('rebuilds thread stats for every thread, or only the ones asked for', function () {
    $forum = statsForum();
    $author = TestUser::make();
    $one = statsThread($forum, $author);
    $two = statsThread($forum, $author);
    statsPost($one, $author);
    statsPost($one, $author);
    statsPost($two, $author);
    statsPost($two, $author);
    DB::table(Tables::name('threads'))->update(['reply_count' => 77]);

    $this->artisan('filagora:forums:sync-thread-stats', ['--thread-id' => [$one->id]])
        ->expectsOutputToContain('1/1 threads updated')->assertExitCode(0);

    expect($one->refresh()->reply_count)->toBe(1)->and($two->refresh()->reply_count)->toBe(77);

    $this->artisan('filagora:forums:sync-thread-stats')->expectsOutputToContain('2/2 threads updated')->assertExitCode(0);

    expect($two->refresh()->reply_count)->toBe(1);
});
