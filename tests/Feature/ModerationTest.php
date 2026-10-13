<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Events\PostModerated;
use Filagora\Forums\Events\PostReported;
use Filagora\Forums\Events\PostReportsResolved;
use Filagora\Forums\Events\ThreadDeleted;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Exceptions\InvalidModeration;
use Filagora\Forums\Exceptions\ModerationNotPermitted;
use Filagora\Forums\Exceptions\PostingNotPermitted;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\PostModeration;
use Filagora\Forums\Services\PostRelocation;
use Filagora\Forums\Services\PostReports;
use Filagora\Forums\Services\ThreadModeration;
use Filagora\Forums\Services\ThreadStats;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    foreach ([ForumAccess::class, ThreadModeration::class, PostModeration::class, PostRelocation::class, PostReports::class] as $service) {
        app()->forgetInstance($service);
    }

    $this->mod = TestUser::make('Mod');
    UserEntitlementGrant::create(['user_id' => $this->mod->id, 'entitlement_key' => Abilities::MODERATE, 'grant_type' => 'manual']);
    $this->author = TestUser::make('Author');
    $this->forum = mForum();
});

function mForum(): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(['forum_group_id' => $group->id, 'name' => 'Forum '.uniqid(), 'slug' => 'forum-'.uniqid()])->refresh();
}

/**
 * A thread with its opening post and `$replies` approved replies, all approved.
 */
function mThread(Forum $forum, TestUser $author, int $replies = 0, string $title = 'T'): Thread
{
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => $title, 'slug' => 't-'.uniqid()])->refresh();

    for ($i = 0; $i <= $replies; $i++) {
        mPost($thread, $author);
    }

    app(ThreadStats::class)->syncForThreadIds([$thread->id]);

    return $thread->refresh();
}

function mPost(Thread $thread, TestUser $author, array $attributes = []): Post
{
    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'b'.uniqid(), 'content_format' => 'markdown'], $attributes))->refresh();
}

function mForumRow(Forum $forum): object
{
    return DB::table((new Forum)->getTable())->where('id', $forum->id)->first();
}

// ------------------------------------------------------------------ authorisation

it('refuses every moderation action to someone who may not moderate', function () {
    $thread = mThread($this->forum, $this->author, 1);
    $post = $thread->posts()->first();
    $other = mThread($this->forum, $this->author);
    $nobody = TestUser::make('Nobody');

    $calls = [
        fn () => app(ThreadModeration::class)->lock($nobody, $thread),
        fn () => app(ThreadModeration::class)->delete($nobody, $thread),
        fn () => app(PostModeration::class)->delete($nobody, $post),
        fn () => app(PostModeration::class)->deleteMany($nobody, [$post]),
        fn () => app(PostRelocation::class)->move($nobody, $post, $other),
        fn () => app(PostReports::class)->resolve($nobody, $post, 'remove'),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(ModerationNotPermitted::class);
    }

    expect($thread->refresh()->is_locked)->toBeFalse()
        ->and(Post::find($post->id)->is_deleted)->toBeFalse()
        ->and(ThreadModerationLog::count())->toBe(0)
        ->and(PostModerationLog::count())->toBe(0);
});

it('does not treat the other staff entitlements as the right to moderate', function () {
    $viewer = TestUser::make('Viewer');
    UserEntitlementGrant::create(['user_id' => $viewer->id, 'entitlement_key' => Abilities::VIEW_UNAPPROVED, 'grant_type' => 'manual']);

    expect(fn () => app(ThreadModeration::class)->pin($viewer, mThread($this->forum, $this->author)))
        ->toThrow(ModerationNotPermitted::class);
});

// ------------------------------------------------------------------ thread actions

it('locks, unlocks, pins and unpins a thread, logging each and telling listeners', function (string $method, string $column, bool $value) {
    Event::fake([ThreadModerated::class]);
    $thread = mThread($this->forum, $this->author);

    if (! $value) {
        $thread->forceFill([$column => true])->save();
    }

    app(ThreadModeration::class)->{$method}($this->mod, $thread, '  because  ');

    $log = ThreadModerationLog::query()->sole();

    expect($thread->refresh()->{$column})->toBe($value)
        ->and($log->action)->toBe($method)
        ->and($log->reason)->toBe('because')
        ->and($log->moderator_id)->toBe($this->mod->id);
    Event::assertDispatched(ThreadModerated::class, fn (ThreadModerated $e) => $e->action === $method && $e->reason === 'because');
})->with([
    'lock' => ['lock', 'is_locked', true],
    'unlock' => ['unlock', 'is_locked', false],
    'pin' => ['pin', 'is_pinned', true],
    'unpin' => ['unpin', 'is_pinned', false],
]);

it('stores a blank reason as null', function () {
    app(ThreadModeration::class)->lock($this->mod, mThread($this->forum, $this->author), '   ');

    expect(ThreadModerationLog::query()->sole()->reason)->toBeNull();
});

it('moves a thread to another forum and recomputes both forums', function () {
    $thread = mThread($this->forum, $this->author, 2);
    $destination = mForum();
    expect(mForumRow($this->forum)->thread_count)->toBe(1)
        ->and(mForumRow($this->forum)->post_count)->toBe(3);

    app(ThreadModeration::class)->move($this->mod, $thread, $destination, 'wrong place');

    expect($thread->refresh()->forum_id)->toBe($destination->id)
        ->and(mForumRow($this->forum)->thread_count)->toBe(0)
        ->and(mForumRow($this->forum)->post_count)->toBe(0)
        ->and(mForumRow($destination)->thread_count)->toBe(1)
        ->and(mForumRow($destination)->post_count)->toBe(3)
        ->and(ThreadModerationLog::query()->sole()->action)->toBe('move');
});

it('refuses to move a thread to the forum it is already in', function () {
    $thread = mThread($this->forum, $this->author);

    expect(fn () => app(ThreadModeration::class)->move($this->mod, $thread, $this->forum))->toThrow(InvalidModeration::class)
        ->and(ThreadModerationLog::count())->toBe(0);
});

it('renames a thread, re-derives a unique slug and records the old and new title', function () {
    mThread($this->forum, $this->author, 0, 'Taken')->forceFill(['slug' => 'brand-new'])->save();
    $thread = mThread($this->forum, $this->author, 0, 'Old');

    app(ThreadModeration::class)->rename($this->mod, $thread, ' Brand New ');

    expect($thread->refresh()->title)->toBe('Brand New')
        ->and($thread->slug)->toBe('brand-new-2')
        ->and(ThreadModerationLog::query()->sole()->reason)->toBe('Renamed from "Old" to "Brand New"');
});

it('does not change the slug when the title is unchanged, and refuses a blank title', function () {
    $thread = mThread($this->forum, $this->author, 0, 'Same');
    $slug = $thread->slug;

    app(ThreadModeration::class)->rename($this->mod, $thread, 'Same');

    expect($thread->refresh()->slug)->toBe($slug)
        ->and(fn () => app(ThreadModeration::class)->rename($this->mod, $thread, '  '))->toThrow(InvalidModeration::class);
});

it('approves a pending thread together with its opening post only, and counts it in the forum', function () {
    $thread = Thread::create(['forum_id' => $this->forum->id, 'author_id' => $this->author->id, 'title' => 'P', 'slug' => 'p', 'moderation_status' => 'pending'])->refresh();
    $opening = mPost($thread, $this->author, ['moderation_status' => 'pending']);
    $reply = mPost($thread, $this->author, ['moderation_status' => 'pending']);
    expect(mForumRow($this->forum)->thread_count)->toBe(0);

    app(ThreadModeration::class)->approve($this->mod, $thread, 'fine');

    expect($thread->refresh()->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($thread->approved_by_id)->toBe($this->mod->id)
        ->and($thread->moderation_reason)->toBe('fine')
        ->and($opening->refresh()->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($reply->refresh()->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and($thread->reply_count)->toBe(0)
        ->and(mForumRow($this->forum)->thread_count)->toBe(1)
        ->and(mForumRow($this->forum)->post_count)->toBe(1)
        ->and(ThreadModerationLog::query()->sole()->action)->toBe('approve');
});

it('deletes a thread and its posts for good, reporting who wrote them', function () {
    Event::fake([ThreadDeleted::class]);
    $thread = mThread($this->forum, $this->author, 1);
    $replier = TestUser::make('Replier');
    mPost($thread, $replier);
    $id = $thread->id;

    app(ThreadModeration::class)->delete($this->mod, $thread, 'spam');

    expect(Thread::find($id))->toBeNull()
        ->and(Post::where('thread_id', $id)->count())->toBe(0)
        ->and(mForumRow($this->forum)->thread_count)->toBe(0)
        ->and(mForumRow($this->forum)->post_count)->toBe(0)
        ->and(ThreadModerationLog::count())->toBe(0);
    Event::assertDispatched(ThreadDeleted::class, fn (ThreadDeleted $e) => $e->threadId === $id
        && $e->forumId === $this->forum->id
        && $e->threadAuthorId === $this->author->id
        && collect($e->postAuthorIds)->sort()->values()->all() === collect([$this->author->id, $replier->id])->sort()->values()->all()
        && $e->reason === 'spam');
});

// ------------------------------------------------------------------ post actions

it('soft deletes a post: flagged and attributed, kept in the forum count, out of the thread count', function () {
    Event::fake([PostModerated::class]);
    $thread = mThread($this->forum, $this->author, 2);
    $post = $thread->posts()->orderByDesc('id')->first();
    expect($thread->reply_count)->toBe(2);

    app(PostModeration::class)->delete($this->mod, $post, 'rude');

    $post->refresh();
    expect($post->is_deleted)->toBeTrue()
        ->and($post->deleted_by_id)->toBe($this->mod->id)
        ->and($post->deleted_at)->not->toBeNull()
        ->and($post->deleted_reason)->toBe('rude')
        ->and($thread->refresh()->reply_count)->toBe(1)
        ->and(mForumRow($this->forum)->post_count)->toBe(3)
        ->and(PostModerationLog::query()->sole()->action)->toBe('delete');
    Event::assertDispatched(PostModerated::class, fn (PostModerated $e) => $e->action === 'delete' && $e->post->is($post));
});

it('stores an empty deleted reason, and a null log reason, when none is given', function () {
    $post = mThread($this->forum, $this->author, 1)->posts()->first();

    app(PostModeration::class)->delete($this->mod, $post);

    expect($post->refresh()->deleted_reason)->toBe('')
        ->and(PostModerationLog::query()->sole()->reason)->toBeNull();
});

it('restores a deleted post and its counts', function () {
    $thread = mThread($this->forum, $this->author, 1);
    $post = $thread->posts()->orderByDesc('id')->first();
    app(PostModeration::class)->delete($this->mod, $post, 'x');

    app(PostModeration::class)->restore($this->mod, $post);

    expect($post->refresh()->is_deleted)->toBeFalse()
        ->and($post->deleted_at)->toBeNull()
        ->and($post->deleted_by_id)->toBeNull()
        ->and($post->deleted_reason)->toBeNull()
        ->and($thread->refresh()->reply_count)->toBe(1)
        ->and(PostModerationLog::orderBy('id')->pluck('action')->all())->toBe(['delete', 'restore']);
});

it('moves a post through the approval states, keeping counts and attribution right', function () {
    $thread = mThread($this->forum, $this->author, 1);
    $post = $thread->posts()->orderByDesc('id')->first();
    $service = app(PostModeration::class);

    $service->unapprove($this->mod, $post, 'check');
    expect($post->refresh()->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and($post->approved_by_id)->toBeNull()
        ->and($post->moderation_reason)->toBe('check')
        ->and($thread->refresh()->reply_count)->toBe(0)
        ->and(mForumRow($this->forum)->post_count)->toBe(1);

    $service->approve($this->mod, $post);
    expect($post->refresh()->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($post->approved_by_id)->toBe($this->mod->id)
        ->and($post->moderation_reason)->toBeNull()
        ->and($thread->refresh()->reply_count)->toBe(1)
        ->and(mForumRow($this->forum)->post_count)->toBe(2);

    $service->reject($this->mod, $post, 'no');
    expect($post->refresh()->moderation_status)->toBe(ContentModerationStatus::REJECTED)
        ->and($post->rejected_by_id)->toBe($this->mod->id)
        ->and($post->rejected_at)->not->toBeNull()
        ->and($post->approved_at)->toBeNull();

    $service->rejectPermanently($this->mod, $post, 'never');
    expect($post->refresh()->moderation_status)->toBe(ContentModerationStatus::REJECTED_FINAL);

    expect(PostModerationLog::orderBy('id')->pluck('action')->all())->toBe(['unapprove', 'approve', 'reject', 'reject_permanently']);
});

it('soft deletes a batch together, and refuses an empty or oversized batch', function () {
    $thread = mThread($this->forum, $this->author, 2);
    $posts = $thread->posts()->get();

    app(PostModeration::class)->deleteMany($this->mod, $posts, 'cleanup');

    expect(Post::where('thread_id', $thread->id)->where('is_deleted', true)->count())->toBe(3)
        ->and(PostModerationLog::count())->toBe(3)
        ->and(fn () => app(PostModeration::class)->deleteMany($this->mod, []))->toThrow(InvalidModeration::class)
        ->and(fn () => app(PostModeration::class)->deleteMany($this->mod, array_fill(0, 201, $posts[0])))->toThrow(InvalidModeration::class);
});

// ------------------------------------------------------------------ relocation

it('moves a post to another thread and re-derives both threads and the forum counts', function () {
    $source = mThread($this->forum, $this->author, 2);
    $destination = mThread(mForum(), $this->author, 0);
    $post = $source->posts()->orderByDesc('id')->first();

    app(PostRelocation::class)->move($this->mod, $post, $destination, 'off topic');

    expect($post->refresh()->thread_id)->toBe($destination->id)
        ->and($source->refresh()->reply_count)->toBe(1)
        ->and($destination->refresh()->reply_count)->toBe(1)
        ->and($destination->last_post_at)->not->toBeNull()
        ->and(mForumRow($this->forum)->post_count)->toBe(2)
        ->and(mForumRow($destination->forum)->post_count)->toBe(2);

    $log = PostModerationLog::query()->sole();
    expect($log->action)->toBe('move')
        ->and($log->thread_id)->toBe($source->id)
        ->and($log->post_id)->toBe($post->id)
        ->and($log->reason)->toBe('off topic');
});

it('refuses to move a post to the thread it is already in', function () {
    $thread = mThread($this->forum, $this->author, 1);

    expect(fn () => app(PostRelocation::class)->move($this->mod, $thread->posts()->first(), $thread))->toThrow(InvalidModeration::class);
});

it('removes a source thread emptied by a move, carrying its post logs and reports to the destination', function () {
    Event::fake([ThreadDeleted::class, PostModerated::class]);
    $source = mThread($this->forum, $this->author, 0);
    $destination = mThread($this->forum, $this->author, 0);
    $post = $source->posts()->first();
    PostModerationLog::create(['thread_id' => $source->id, 'post_id' => $post->id, 'action' => 'approve']);
    $report = PostReport::create(['post_id' => $post->id, 'thread_id' => $source->id, 'reporter_id' => $this->author->id, 'reason' => 'r']);
    $sourceId = $source->id;

    app(PostRelocation::class)->move($this->mod, $post, $destination);

    expect(Thread::find($sourceId))->toBeNull()
        ->and($report->refresh()->thread_id)->toBe($destination->id)
        ->and(PostModerationLog::where('thread_id', $destination->id)->count())->toBe(2)
        ->and(mForumRow($this->forum)->thread_count)->toBe(1);
    Event::assertDispatched(ThreadDeleted::class, fn (ThreadDeleted $e) => $e->threadId === $sourceId);
    Event::assertDispatched(PostModerated::class, fn (PostModerated $e) => $e->action === 'move');
});

it('moves a batch, skipping posts already in the destination', function () {
    $source = mThread($this->forum, $this->author, 2);
    $destination = mThread($this->forum, $this->author, 0);
    $posts = $source->posts()->get()->push($destination->posts()->first());

    app(PostRelocation::class)->movePosts($this->mod, $posts, $destination);

    expect(Post::where('thread_id', $destination->id)->count())->toBe(4)
        ->and(PostModerationLog::count())->toBe(3)
        ->and(Thread::find($source->id))->toBeNull()
        ->and($destination->refresh()->reply_count)->toBe(3);
});

it('promotes a post into a new thread that takes its author, state and a unique slug', function () {
    Event::fake([PostModerated::class]);
    $source = mThread($this->forum, $this->author, 1);
    $other = TestUser::make('Other');
    $post = mPost($source, $other);
    $target = mForum();
    mThread($target, $this->author, 0, 'Split')->forceFill(['slug' => 'split'])->save();

    $thread = app(PostRelocation::class)->promote($this->mod, $post, $target, 'Split');

    expect($thread->forum_id)->toBe($target->id)
        ->and($thread->author_id)->toBe($other->id)
        ->and($thread->slug)->toBe('split-2')
        ->and($thread->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($thread->is_locked)->toBeFalse()
        ->and($post->refresh()->thread_id)->toBe($thread->id)
        ->and($thread->reply_count)->toBe(0)
        ->and($source->refresh()->reply_count)->toBe(1)
        ->and(mForumRow($target)->thread_count)->toBe(2)
        ->and(PostModerationLog::query()->sole()->action)->toBe('promote');
    Event::assertDispatched(PostModerated::class, fn (PostModerated $e) => $e->action === 'promote');
});

it('promotes several posts into one thread, earliest first, logging the rest as moves', function () {
    $source = mThread($this->forum, $this->author, 2);
    [$first, $second, $third] = $source->posts()->orderBy('id')->get()->all();
    $first->forceFill(['created_at' => now()->subHour()])->save();

    $thread = app(PostRelocation::class)->promotePosts($this->mod, [$third, $first], mForum(), 'Branch');

    expect(Post::where('thread_id', $thread->id)->count())->toBe(2)
        ->and($thread->reply_count)->toBe(1)
        ->and($source->refresh()->reply_count)->toBe(0)
        ->and(PostModerationLog::where('post_id', $first->id)->sole()->action)->toBe('promote')
        ->and(PostModerationLog::where('post_id', $third->id)->sole()->action)->toBe('move');
});

it('promotes a post out of its only thread, removing the emptied thread', function () {
    $source = mThread($this->forum, $this->author, 0);
    $sourceId = $source->id;

    $thread = app(PostRelocation::class)->promote($this->mod, $source->posts()->first(), $this->forum, 'Same forum');

    expect(Thread::find($sourceId))->toBeNull()
        ->and(Thread::find($thread->id))->not->toBeNull()
        ->and(mForumRow($this->forum)->thread_count)->toBe(1)
        ->and(mForumRow($this->forum)->post_count)->toBe(1);
});

it('refuses an empty, oversized or untitled relocation', function () {
    $post = mThread($this->forum, $this->author, 0)->posts()->first();
    $service = app(PostRelocation::class);

    expect(fn () => $service->movePosts($this->mod, [], mThread($this->forum, $this->author)))->toThrow(InvalidModeration::class)
        ->and(fn () => $service->movePosts($this->mod, array_fill(0, 201, $post), mThread($this->forum, $this->author)))->toThrow(InvalidModeration::class)
        ->and(fn () => $service->promote($this->mod, $post, $this->forum, '  '))->toThrow(InvalidModeration::class);
});

// ------------------------------------------------------------------ reports

it('lets a member report a visible approved post, once', function () {
    Event::fake([PostReported::class]);
    $post = mThread($this->forum, $this->author, 0)->posts()->first();
    $reporter = TestUser::make('Reporter');

    $report = app(PostReports::class)->report($reporter, $post, '  spam  ');

    expect($report->status)->toBe(PostReportStatus::OPEN)
        ->and($report->reason)->toBe('spam')
        ->and($report->thread_id)->toBe($post->thread_id)
        ->and(fn () => app(PostReports::class)->report($reporter, $post, 'again'))->toThrow(InvalidModeration::class)
        ->and(PostReport::count())->toBe(1);
    Event::assertDispatched(PostReported::class);
});

it('does not accept reports on deleted or unapproved posts, or from someone who cannot see the forum', function () {
    $thread = mThread($this->forum, $this->author, 2);
    [$a, $b] = $thread->posts()->orderBy('id')->get()->all();
    $a->forceFill(['is_deleted' => true])->save();
    $b->forceFill(['moderation_status' => 'pending'])->save();
    $reporter = TestUser::make('Reporter');
    $reports = app(PostReports::class);

    expect(fn () => $reports->report($reporter, $a, 'x'))->toThrow(InvalidModeration::class)
        ->and(fn () => $reports->report($reporter, $b, 'x'))->toThrow(InvalidModeration::class);

    $hidden = mForum();
    $hidden->forceFill(['visibility' => 'staff_only'])->save();
    $secret = mThread($hidden, $this->author, 0)->posts()->first();

    expect(fn () => $reports->report($reporter, $secret, 'x'))->toThrow(PostingNotPermitted::class)
        ->and(PostReport::count())->toBe(0);
});

function mReported(Post $post, int $count = 2): array
{
    $reports = [];

    for ($i = 0; $i < $count; $i++) {
        $reports[] = PostReport::create(['post_id' => $post->id, 'thread_id' => $post->thread_id, 'reporter_id' => TestUser::make("R{$i}")->id, 'reason' => 'r']);
    }

    return $reports;
}

it('dismisses every open report on the post and leaves the post alone', function () {
    Event::fake([PostReportsResolved::class]);
    $post = mThread($this->forum, $this->author, 1)->posts()->orderByDesc('id')->first();
    $reports = mReported($post);
    $closed = PostReport::create(['post_id' => $post->id, 'thread_id' => $post->thread_id, 'reporter_id' => $this->author->id, 'reason' => 'old', 'status' => 'reviewed']);

    app(PostReports::class)->resolve($this->mod, $post, 'dismiss', ' not a problem ');

    foreach ($reports as $report) {
        $report->refresh();
        expect($report->status)->toBe(PostReportStatus::DISMISSED)
            ->and($report->resolution_note)->toBe('not a problem')
            ->and($report->reviewed_by_id)->toBe($this->mod->id)
            ->and($report->reviewed_at)->not->toBeNull();
    }

    expect($closed->refresh()->status)->toBe(PostReportStatus::REVIEWED)
        ->and($closed->reviewed_by_id)->toBeNull()
        ->and($post->refresh()->is_deleted)->toBeFalse();
    Event::assertDispatched(PostReportsResolved::class, fn (PostReportsResolved $e) => $e->outcome === 'dismiss'
        && count($e->reporterIds) === 2 && ! $e->threadRemoved);
});

it('marks reports reviewed when the post is kept, and treats an unknown outcome as keep', function (string $outcome) {
    $post = mThread($this->forum, $this->author, 1)->posts()->orderByDesc('id')->first();
    [$report] = mReported($post, 1);

    app(PostReports::class)->resolve($this->mod, $post, $outcome);

    expect($report->refresh()->status)->toBe(PostReportStatus::REVIEWED)
        ->and($post->refresh()->is_deleted)->toBeFalse();
})->with(['keep', 'nonsense']);

it('removes the reported post when asked, with a default reason', function () {
    $thread = mThread($this->forum, $this->author, 1);
    $post = $thread->posts()->orderByDesc('id')->first();
    [$report] = mReported($post, 1);

    app(PostReports::class)->resolve($this->mod, $post, 'remove');

    expect($report->refresh()->status)->toBe(PostReportStatus::REVIEWED)
        ->and($post->refresh()->is_deleted)->toBeTrue()
        ->and($post->deleted_reason)->toBe('Removed after report')
        ->and($thread->refresh()->reply_count)->toBe(0)
        ->and(PostModerationLog::query()->sole()->action)->toBe('delete');
});

it('removes the whole thread when the reported post is all it holds', function () {
    Event::fake([ThreadDeleted::class, PostReportsResolved::class]);
    $thread = mThread($this->forum, $this->author, 0);
    $post = $thread->posts()->first();
    mReported($post, 1);
    $id = $thread->id;

    app(PostReports::class)->resolve($this->mod, $post, 'remove_thread', 'spam thread');

    expect(Thread::find($id))->toBeNull()
        ->and(mForumRow($this->forum)->thread_count)->toBe(0);
    Event::assertDispatched(ThreadDeleted::class, fn (ThreadDeleted $e) => $e->threadId === $id);
    Event::assertDispatched(PostReportsResolved::class, fn (PostReportsResolved $e) => $e->threadRemoved);
});

it('removes only the reply when the thread has other approved posts', function () {
    $thread = mThread($this->forum, $this->author, 1);
    $post = $thread->posts()->orderByDesc('id')->first();
    mReported($post, 1);

    app(PostReports::class)->resolve($this->mod, $post, 'remove_thread');

    expect(Thread::find($thread->id))->not->toBeNull()
        ->and($post->refresh()->is_deleted)->toBeTrue();
});

it('knows whether a post is the only approved undeleted one in its thread', function () {
    $thread = mThread($this->forum, $this->author, 1);
    [$a, $b] = $thread->posts()->orderBy('id')->get()->all();
    $reports = app(PostReports::class);

    expect($reports->isOnlyPost($a))->toBeFalse();

    $b->forceFill(['is_deleted' => true])->save();
    expect($reports->isOnlyPost($a))->toBeTrue();

    $b->forceFill(['is_deleted' => false, 'moderation_status' => 'pending'])->save();
    expect($reports->isOnlyPost($a))->toBeTrue();
});
