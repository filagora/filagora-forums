<?php

declare(strict_types=1);

use ForumKit\Core\Support\Tables;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Enums\ForumPostingPolicy;
use ForumKit\Forums\Enums\ForumVisibility;
use ForumKit\Forums\Enums\PostReportStatus;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\ForumGroup;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\PostModerationLog;
use ForumKit\Forums\Models\PostReport;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Models\ThreadModerationLog;
use ForumKit\Forums\Support\Models;
use ForumKit\Forums\Tests\Support\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomThread extends Thread {}

function makeForum(string $slug = 'general', array $overrides = []): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(array_merge(['forum_group_id' => $group->id, 'name' => ucfirst($slug), 'slug' => $slug], $overrides));
}

function makeThread(Forum $forum, TestUser $author, string $slug = 'hello', array $overrides = []): Thread
{
    return Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'Hello', 'slug' => $slug], $overrides));
}

function makePost(Thread $thread, TestUser $author, array $overrides = []): Post
{
    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Body'], $overrides));
}

// ---------------------------------------------------------------- the stored data loads

it('loads every stored visibility, posting policy, moderation status and report status', function () {
    $author = TestUser::make();
    $forum = makeForum();

    foreach (ForumVisibility::cases() as $i => $case) {
        DB::table(Tables::name('forums'))->insert(['forum_group_id' => $forum->forum_group_id, 'name' => "v{$i}", 'slug' => "v{$i}", 'visibility' => $case->value]);
    }

    foreach (ForumPostingPolicy::cases() as $i => $case) {
        DB::table(Tables::name('forums'))->insert(['forum_group_id' => $forum->forum_group_id, 'name' => "p{$i}", 'slug' => "p{$i}", 'posting_policy' => $case->value]);
    }

    foreach (ContentModerationStatus::cases() as $i => $case) {
        DB::table(Tables::name('threads'))->insert(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => "t{$i}", 'slug' => "t{$i}", 'moderation_status' => $case->value]);
    }

    $thread = makeThread($forum, $author);

    foreach (ContentModerationStatus::cases() as $case) {
        DB::table(Tables::name('posts'))->insert(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'b', 'moderation_status' => $case->value]);
    }

    $post = Post::query()->first();

    foreach (PostReportStatus::cases() as $case) {
        DB::table(Tables::name('post_reports'))->insert(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $author->id, 'reason' => 'r', 'status' => $case->value]);
    }

    expect(Forum::all()->pluck('visibility')->filter()->unique()->map->value->sort()->values()->all())->toBe(collect(ForumVisibility::cases())->map->value->sort()->values()->all())
        ->and(Forum::all()->pluck('posting_policy')->map->value->unique()->sort()->values()->all())->toBe(collect(ForumPostingPolicy::cases())->map->value->sort()->values()->all())
        ->and(Thread::all()->pluck('moderation_status')->map->value->unique()->sort()->values()->all())->toBe(collect(ContentModerationStatus::cases())->map->value->sort()->values()->all())
        ->and(Post::all()->pluck('moderation_status')->map->value->unique()->count())->toBe(4)
        ->and(PostReport::all()->pluck('status')->map->value->sort()->values()->all())->toBe(['dismissed', 'open', 'reviewed']);
});

it('keeps moderation log actions free-form, so add-ons can write their own', function () {
    $author = TestUser::make();
    $thread = makeThread(makeForum(), $author);
    $post = makePost($thread, $author);

    foreach (['approve', 'move', 'promote', 'poll_created', 'something_an_addon_invented'] as $action) {
        ThreadModerationLog::create(['thread_id' => $thread->id, 'action' => $action]);
        PostModerationLog::create(['thread_id' => $thread->id, 'post_id' => $post->id, 'action' => $action]);
    }

    expect(ThreadModerationLog::pluck('action')->all())->toContain('poll_created', 'something_an_addon_invented')
        ->and(PostModerationLog::count())->toBe(5);
});

it('defaults a new forum, thread and post to the stored defaults', function () {
    $author = TestUser::make();
    $forum = makeForum()->refresh();
    $thread = makeThread($forum, $author)->refresh();
    $post = makePost($thread, $author)->refresh();

    expect($forum->visibility)->toBe(ForumVisibility::PUBLIC)
        ->and($forum->posting_policy)->toBe(ForumPostingPolicy::REGISTERED)
        ->and($forum->is_active)->toBeTrue()
        ->and($forum->thread_count)->toBe(0)
        ->and($thread->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($thread->is_pinned)->toBeFalse()
        ->and($post->content_format)->toBe('markdown')
        ->and($post->is_deleted)->toBeFalse()
        ->and($post->isApproved())->toBeTrue();
});

// ------------------------------------------------------------ the generated column

it('computes last_activity_at in the database', function () {
    $author = TestUser::make();
    $forum = makeForum();
    $created = Carbon::parse('2026-03-10 12:00:00');

    $insert = fn (string $slug, ?Carbon $lastPostAt): int => DB::table(Tables::name('threads'))->insertGetId([
        'forum_id' => $forum->id, 'author_id' => $author->id, 'title' => $slug, 'slug' => $slug,
        'created_at' => $created, 'last_post_at' => $lastPostAt,
    ]);

    $never = $insert('never-replied', null);
    $before = $insert('stale-last-post', $created->copy()->subDay());
    $after = $insert('replied', $created->copy()->addDays(3));

    expect(Thread::find($never)->last_activity_at->toDateTimeString())->toBe('2026-03-10 12:00:00')
        ->and(Thread::find($before)->last_activity_at->toDateTimeString())->toBe('2026-03-10 12:00:00')
        ->and(Thread::find($after)->last_activity_at->toDateTimeString())->toBe('2026-03-13 12:00:00');
});

it('refuses to write the generated column', function () {
    $author = TestUser::make();
    $thread = makeThread(makeForum(), $author);

    expect(Thread::query()->getModel()->getFillable())->not->toContain('last_activity_at')
        ->and(fn () => DB::table(Tables::name('threads'))->where('id', $thread->id)->update(['last_activity_at' => now()]))
        ->toThrow(QueryException::class);
});

// ----------------------------------------------------------------- relations

it('links groups, forums, threads, posts, reports and logs together', function () {
    $author = TestUser::make('Author');
    $moderator = TestUser::make('Moderator');
    $forum = makeForum();
    $thread = makeThread($forum, $author, overrides: ['approved_by_id' => $moderator->id]);
    $post = makePost($thread, $author, ['edited_by_id' => $moderator->id]);
    $report = PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $moderator->id, 'reason' => 'Spam']);
    $postLog = PostModerationLog::create(['thread_id' => $thread->id, 'post_id' => $post->id, 'moderator_id' => $moderator->id, 'action' => 'approve']);
    $threadLog = ThreadModerationLog::create(['thread_id' => $thread->id, 'moderator_id' => $moderator->id, 'action' => 'approve']);

    expect($forum->group->name)->toBe('Main')
        ->and($forum->group->forums->pluck('id')->all())->toBe([$forum->id])
        ->and($forum->threads->pluck('id')->all())->toBe([$thread->id])
        ->and($thread->forum->is($forum))->toBeTrue()
        ->and($thread->author->is($author))->toBeTrue()
        ->and($thread->approvedBy->is($moderator))->toBeTrue()
        ->and($thread->posts->pluck('id')->all())->toBe([$post->id])
        ->and($thread->reports->pluck('id')->all())->toBe([$report->id])
        ->and($thread->moderationLogs->pluck('id')->all())->toBe([$threadLog->id])
        ->and($post->thread->is($thread))->toBeTrue()
        ->and($post->author->is($author))->toBeTrue()
        ->and($post->editedBy->is($moderator))->toBeTrue()
        ->and($post->moderationLogs->pluck('id')->all())->toBe([$postLog->id])
        ->and($post->reports->pluck('id')->all())->toBe([$report->id])
        ->and($report->post->is($post))->toBeTrue()
        ->and($report->reporter->is($moderator))->toBeTrue()
        ->and($postLog->moderator->is($moderator))->toBeTrue()
        ->and($threadLog->thread->is($thread))->toBeTrue();
});

it('orders a group\'s forums by sort order and scopes approved content', function () {
    $author = TestUser::make();
    $second = makeForum('second', ['sort_order' => 2]);
    $first = makeForum('first', ['sort_order' => 1]);
    $thread = makeThread($first, $author, 'a');
    makeThread($first, $author, 'b', ['moderation_status' => ContentModerationStatus::PENDING]);
    makePost($thread, $author);
    makePost($thread, $author, ['moderation_status' => ContentModerationStatus::REJECTED]);

    expect($first->group->forums->pluck('slug')->all())->toBe(['first', 'second'])
        ->and(Thread::approved()->count())->toBe(1)
        ->and(Post::approved()->count())->toBe(1)
        ->and(Thread::where('slug', 'b')->first()->isPending())->toBeTrue()
        ->and(Post::where('moderation_status', 'rejected')->first()->isRejected())->toBeTrue()
        ->and($second->id)->not->toBe($first->id);
});

// ---------------------------------------------------------- cascades and nulling

it('cascades deletes down from a forum to its threads, posts, reports and logs', function () {
    $author = TestUser::make();
    $forum = makeForum();
    $thread = makeThread($forum, $author);
    $post = makePost($thread, $author);
    PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $author->id, 'reason' => 'x']);
    PostModerationLog::create(['thread_id' => $thread->id, 'post_id' => $post->id, 'action' => 'approve']);
    ThreadModerationLog::create(['thread_id' => $thread->id, 'action' => 'approve']);

    $forum->delete();

    foreach (['threads', 'posts', 'post_reports', 'post_moderation_logs', 'thread_moderation_logs'] as $table) {
        expect(DB::table(Tables::name($table))->count())->toBe(0, $table);
    }

    expect(ForumGroup::count())->toBe(1);
});

it('removes an author\'s threads and posts with them, but only clears who moderated', function () {
    $author = TestUser::make('Author');
    $moderator = TestUser::make('Moderator');
    $forum = makeForum();
    $thread = makeThread($forum, $author, overrides: ['approved_by_id' => $moderator->id, 'last_post_author_id' => $moderator->id]);
    $post = makePost($thread, $author, ['approved_by_id' => $moderator->id, 'deleted_by_id' => $moderator->id]);
    $log = ThreadModerationLog::create(['thread_id' => $thread->id, 'moderator_id' => $moderator->id, 'action' => 'approve']);

    $moderator->delete();

    expect($thread->refresh()->approved_by_id)->toBeNull()
        ->and($thread->last_post_author_id)->toBeNull()
        ->and($post->refresh()->approved_by_id)->toBeNull()
        ->and($post->deleted_by_id)->toBeNull()
        ->and($log->refresh()->moderator_id)->toBeNull();

    $author->delete();

    expect(Thread::count())->toBe(0)->and(Post::count())->toBe(0);
});

// ------------------------------------------------------------- swappable models

it('resolves model classes from config, with the host key winning', function () {
    expect(Models::class('thread'))->toBe(Thread::class);

    config(['forumkit-forums.models.thread' => CustomThread::class]);
    expect(Models::class('thread'))->toBe(CustomThread::class);

    config(['forumkit.models.thread' => Thread::class]);
    expect(Models::class('thread'))->toBe(Thread::class);
});

it('follows a swapped model through every relation', function () {
    $author = TestUser::make();
    $forum = makeForum();
    $thread = makeThread($forum, $author);
    makePost($thread, $author);

    config(['forumkit.models.thread' => CustomThread::class]);

    expect(Post::first()->thread)->toBeInstanceOf(CustomThread::class)
        ->and(Forum::first()->threads->first())->toBeInstanceOf(CustomThread::class);
});

it('names an unknown model key', function () {
    Models::class('nope');
})->throws(InvalidArgumentException::class, 'No ForumKit forums model is registered for [nope]');
