<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\ForumRead;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadRead;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\ReadTracking;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Time is frozen at noon on a fixed day, so "an hour ago" and "two days ago" mean the same thing
 * whenever the suite runs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(ReadTracking::class);
});

afterEach(fn () => Carbon::setTestNow());

function readForum(string $name = 'General', ForumVisibility $visibility = ForumVisibility::PUBLIC): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(['forum_group_id' => $group->id, 'name' => $name, 'slug' => 'f-'.uniqid(), 'visibility' => $visibility]);
}

function readThread(Forum $forum, ?Carbon $lastPost = null, ?Carbon $created = null): Thread
{
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => TestUser::make('Author')->id, 'title' => 'T', 'slug' => 't-'.uniqid(), 'last_post_at' => $lastPost]);
    $created ??= $lastPost?->copy()->subDay();

    if ($created !== null) {
        DB::table($thread->getTable())->where('id', $thread->id)->update(['created_at' => $created]);
    }

    return $thread->refresh();
}

function tracking(): ReadTracking
{
    return app(ReadTracking::class);
}

function setBaseline(TestUser $user, ?Carbon $baseline): TestUser
{
    $user->forceFill(['forum_read_baseline_at' => $baseline])->save();

    return $user->refresh();
}

it('reads the baseline from the configured user column, or has none', function () {
    $user = TestUser::make('Reader');

    expect(tracking()->baseline($user))->toBeNull();

    setBaseline($user, now()->subDays(3));
    expect(tracking()->baseline($user)?->toDateTimeString())->toBe('2026-06-12 12:00:00');

    config(['filagora-forums.read_tracking.baseline_attribute' => null]);
    expect(tracking()->baseline($user))->toBeNull();

    config(['filagora-forums.read_tracking.baseline_attribute' => 'no_such_column']);
    expect(tracking()->baseline($user))->toBeNull();
});

it('limits how far back a list of unread threads looks, whatever the baseline', function () {
    $user = TestUser::make('Reader');

    expect(tracking()->floor($user)->toDateTimeString())->toBe('2025-06-15 12:00:00');

    setBaseline($user, now()->subYears(10));
    expect(tracking()->floor($user)->toDateTimeString())->toBe('2025-06-15 12:00:00');

    setBaseline($user, now()->subDays(10));
    expect(tracking()->floor($user)->toDateTimeString())->toBe('2026-06-05 12:00:00');

    config(['filagora-forums.read_tracking.unread_max_lookback_days' => 30]);
    setBaseline($user, now()->subDays(100));
    expect(tracking()->floor($user)->toDateTimeString())->toBe('2026-05-16 12:00:00');
});

it('counts a thread as unread until it is opened, and again after new activity', function () {
    $user = TestUser::make('Reader');
    $thread = readThread(readForum(), lastPost: now()->subHour());

    expect(tracking()->isUnread($user, $thread))->toBeTrue();

    tracking()->markThreadRead($user, $thread);
    expect(tracking()->isUnread($user, $thread))->toBeFalse();

    $this->travel(2)->hours();
    $thread->forceFill(['last_post_at' => now()->subMinute()])->save();
    expect(tracking()->isUnread($user, $thread->refresh()))->toBeTrue();
});

it('keeps one person\'s reading apart from another\'s', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $thread = readThread(readForum(), lastPost: now()->subHour());

    tracking()->markThreadRead($alice, $thread);

    expect(tracking()->isUnread($alice, $thread))->toBeFalse()->and(tracking()->isUnread($bob, $thread))->toBeTrue();
});

it('treats a thread with no replies by its creation time', function () {
    $user = TestUser::make('Reader');
    $thread = readThread(readForum(), created: now()->subDays(2));

    setBaseline($user, now()->subDay());
    expect(tracking()->isUnread($user, $thread))->toBeFalse();

    setBaseline($user, now()->subDays(3));
    expect(tracking()->isUnread($user, $thread))->toBeTrue();
});

it('treats everything before the baseline as read, and nothing as read without one', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();
    $old = readThread($forum, lastPost: now()->subDays(5));
    $new = readThread($forum, lastPost: now()->subHour());

    expect(tracking()->unreadAmong($user, [$old, $new]))->toBe([$old->id, $new->id]);

    setBaseline($user, now()->subDay());
    expect(tracking()->unreadAmong($user, [$old, $new]))->toBe([$new->id]);
});

it('lets a forum marked read hide older activity in it, and only in it', function () {
    $user = TestUser::make('Reader');
    $one = readForum('One');
    $two = readForum('Two');
    $inOne = readThread($one, lastPost: now()->subHour());
    $inTwo = readThread($two, lastPost: now()->subHour());

    expect(tracking()->markForumsRead($user, [$one->id]))->toBe(1)
        ->and(tracking()->unreadAmong($user, [$inOne, $inTwo]))->toBe([$inTwo->id]);

    $this->travel(1)->hours();
    $inOne->forceFill(['last_post_at' => now()->subMinute()])->save();
    expect(tracking()->unreadAmong($user, [$inOne->refresh(), $inTwo]))->toBe([$inOne->id, $inTwo->id]);
});

it('lets a hand-made unread flag beat every read time, until the thread is opened again', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();
    $thread = readThread($forum, lastPost: now()->subDays(2));
    setBaseline($user, now()->subDay());
    tracking()->markForumsRead($user, [$forum->id]);
    tracking()->markThreadRead($user, $thread);
    expect(tracking()->isUnread($user, $thread))->toBeFalse();

    tracking()->markThreadUnread($user, $thread);
    expect(tracking()->isUnread($user, $thread))->toBeTrue()
        ->and(ThreadRead::where('thread_id', $thread->id)->sole()->is_unread_override)->toBeTrue();

    tracking()->markThreadRead($user, $thread);
    expect(tracking()->isUnread($user, $thread))->toBeFalse()
        ->and(ThreadRead::where('thread_id', $thread->id)->count())->toBe(1)
        ->and(ThreadRead::where('thread_id', $thread->id)->sole()->is_unread_override)->toBeFalse();
});

it('marks a forum read once however often it is asked, and ignores an empty list', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();

    expect(tracking()->markForumsRead($user, []))->toBe(0)
        ->and(tracking()->markForumsRead($user, [$forum->id, $forum->id]))->toBe(1);

    $this->travel(1)->hour();
    tracking()->markForumsRead($user, [$forum->id]);

    $read = ForumRead::where('user_id', $user->id)->sole();
    expect($read->last_read_at->toDateTimeString())->toBe('2026-06-15 13:00:00');
});

it('clears hand-made unread flags only in the forums it marks read', function () {
    $user = TestUser::make('Reader');
    $one = readForum('One');
    $two = readForum('Two');
    $inOne = readThread($one);
    $inTwo = readThread($two);
    tracking()->markThreadUnread($user, $inOne);
    tracking()->markThreadUnread($user, $inTwo);

    tracking()->markForumsRead($user, [$one->id]);

    expect(ThreadRead::where('thread_id', $inOne->id)->sole()->is_unread_override)->toBeFalse()
        ->and(ThreadRead::where('thread_id', $inTwo->id)->sole()->is_unread_override)->toBeTrue();
});

it('marks everything the person can see as read, and nothing else', function () {
    $member = TestUser::make('Member');
    $public = readForum('Public');
    $registered = readForum('Registered', ForumVisibility::REGISTERED);
    $staffOnly = readForum('Staff', ForumVisibility::STAFF_ONLY);

    expect(tracking()->markAllRead($member))->toBe(2)
        ->and(ForumRead::where('user_id', $member->id)->pluck('forum_id')->sort()->values()->all())->toBe([$public->id, $registered->id]);

    $staff = TestUser::make('Staff');
    UserEntitlementGrant::create(['user_id' => $staff->id, 'entitlement_key' => Abilities::MODERATE, 'grant_type' => 'manual']);
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(ReadTracking::class);

    expect(tracking()->markAllRead($staff))->toBe(3)
        ->and(ForumRead::where('user_id', $staff->id)->pluck('forum_id')->sort()->values()->all())->toBe([$public->id, $registered->id, $staffOnly->id]);
});

it('does not mark inactive forums read for staff', function () {
    $staff = TestUser::make('Staff');
    UserEntitlementGrant::create(['user_id' => $staff->id, 'entitlement_key' => Abilities::MODERATE, 'grant_type' => 'manual']);
    $active = readForum('Active');
    Forum::create(['forum_group_id' => $active->forum_group_id, 'name' => 'Off', 'slug' => 'off', 'is_active' => false]);

    expect(tracking()->markAllRead($staff))->toBe(1);
});

it('lists unread threads since the floor, hiding what was read at thread or forum level', function () {
    $user = TestUser::make('Reader');
    $one = readForum('One');
    $two = readForum('Two');
    $old = readThread($one, lastPost: now()->subYears(2));
    $fresh = readThread($one, lastPost: now()->subHours(3));
    $readThread = readThread($one, lastPost: now()->subHours(4));
    $inReadForum = readThread($two, lastPost: now()->subHours(5));
    tracking()->markThreadRead($user, $readThread);
    tracking()->markForumsRead($user, [$two->id]);

    $ids = tracking()->unreadThreads($user)->pluck('id')->all();
    expect(array_map('intval', $ids))->toBe([$fresh->id])
        ->and(in_array($old->id, $ids, true) || in_array($inReadForum->id, $ids, true))->toBeFalse();
});

it('includes a thread flagged unread by hand whatever its age', function () {
    $user = TestUser::make('Reader');
    $old = readThread(readForum(), lastPost: now()->subYears(3));
    $other = readThread(readForum('Other'), lastPost: now()->subYears(3));
    tracking()->markThreadUnread($user, $old);

    expect(tracking()->unreadThreads($user)->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$old->id])
        ->and($other->id)->not->toBe($old->id);
});

it('lists a thread after new activity even if it was read, and respects the baseline', function () {
    $user = TestUser::make('Reader');
    $thread = readThread(readForum(), lastPost: now()->subHours(6));
    tracking()->markThreadRead($user, $thread);
    expect(tracking()->unreadThreads($user)->count())->toBe(0);

    $this->travel(7)->hours();
    $thread->forceFill(['last_post_at' => now()->subHour()])->save();
    expect(tracking()->unreadThreads($user)->count())->toBe(1);

    setBaseline($user, now());
    expect(tracking()->unreadThreads($user)->count())->toBe(0);
});

it('narrows an unread list to the query it is given, and keeps its columns', function () {
    $user = TestUser::make('Reader');
    $one = readForum('One');
    $two = readForum('Two');
    $inOne = readThread($one, lastPost: now()->subHour());
    readThread($two, lastPost: now()->subHour());

    $query = tracking()->unreadThreads($user, Thread::query()->where('forum_id', $one->id));

    expect($query->get()->pluck('id')->all())->toBe([$inOne->id])
        ->and($query->first()->title)->toBe('T')
        ->and($query->first()->getAttributes())->not->toHaveKey('is_unread_override');
});

it('removes a thread\'s reads with the thread, and a person\'s with the person', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();
    $thread = readThread($forum);
    tracking()->markThreadRead($user, $thread);
    tracking()->markForumsRead($user, [$forum->id]);

    $thread->delete();
    expect(ThreadRead::count())->toBe(0)->and(ForumRead::count())->toBe(1);

    $user->delete();
    expect(ForumRead::count())->toBe(0);
});

it('has the same unique rows and indexes as the forum it was extracted from', function () {
    $user = TestUser::make('Reader');
    $thread = readThread(readForum());
    ThreadRead::create(['user_id' => $user->id, 'thread_id' => $thread->id, 'last_read_at' => now()]);

    expect(fn () => ThreadRead::create(['user_id' => $user->id, 'thread_id' => $thread->id, 'last_read_at' => now()]))->toThrow(QueryException::class)
        ->and(ThreadRead::first()->is_unread_override)->toBeFalse();
});

it('never looks back less than a day, however the lookback is set', function () {
    $user = TestUser::make('Reader');
    config(['filagora-forums.read_tracking.unread_max_lookback_days' => 0]);

    expect(tracking()->floor($user)->toDateTimeString())->toBe('2026-06-14 12:00:00');
});

it('counts a thread read at the very moment of its last activity as read', function () {
    $user = TestUser::make('Reader');
    $thread = readThread(readForum(), lastPost: now());
    tracking()->markThreadRead($user, $thread);

    expect(tracking()->isUnread($user, $thread))->toBeFalse()
        ->and(tracking()->unreadThreads($user)->count())->toBe(0);
});

it('does not list a forum read at the very moment of the last activity', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();
    readThread($forum, lastPost: now());
    tracking()->markForumsRead($user, [$forum->id]);

    expect(tracking()->unreadThreads($user)->count())->toBe(0);
});

it('leaves out activity exactly at the floor', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();
    $atFloor = readThread($forum, lastPost: now()->subYear());
    $after = readThread($forum, lastPost: now()->subYear()->addSecond());

    expect(tracking()->unreadThreads($user)->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$after->id])
        ->and($atFloor->id)->not->toBe($after->id);
});

it('takes the newest of the thread, forum and baseline read times', function () {
    $user = TestUser::make('Reader');
    $forum = readForum();
    $thread = readThread($forum, lastPost: now()->subHours(2));
    tracking()->markForumsRead($user, [$forum->id]);
    $this->travel(3)->hours();
    $laterThread = readThread($forum, lastPost: now()->subHours(2));
    tracking()->markThreadRead($user, $thread);
    $this->travel(1)->hour();
    $thread->forceFill(['last_post_at' => now()->subMinutes(30)])->save();
    tracking()->markThreadRead($user, $thread->refresh());

    $other = readThread($forum, lastPost: now()->subMinutes(40));

    expect(tracking()->unreadAmong($user, [$thread, $laterThread, $other]))->toBe([$laterThread->id, $other->id]);
});

it('uses the creation time when the last post is older than the thread', function () {
    $user = TestUser::make('Reader');
    $thread = readThread(readForum(), lastPost: now()->subDays(5), created: now()->subHour());
    setBaseline($user, now()->subDay());

    expect(tracking()->isUnread($user, $thread))->toBeTrue();
});

it('does not let one person\'s reading change what another sees', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $forum = readForum();
    $thread = readThread($forum, lastPost: now()->subHour());
    tracking()->markThreadRead($bob, $thread);
    tracking()->markForumsRead($bob, [$forum->id]);

    expect(tracking()->unreadAmong($alice, [$thread]))->toBe([$thread->id])
        ->and(tracking()->unreadThreads($alice)->count())->toBe(1)
        ->and(tracking()->unreadThreads($bob)->count())->toBe(0);
});

it('does not list another person\'s hand-made unread flag', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $old = readThread(readForum(), lastPost: now()->subYears(3));
    tracking()->markThreadUnread($bob, $old);

    expect(tracking()->unreadThreads($alice)->count())->toBe(0)->and(tracking()->unreadThreads($bob)->count())->toBe(1);
});

it('marks read the forums where the person sees only their own threads', function () {
    $member = TestUser::make('Member');
    $public = readForum('Public');
    $private = readForum('Private', ForumVisibility::STAFF_AUTHOR_ONLY);
    Thread::create(['forum_id' => $private->id, 'author_id' => $member->id, 'title' => 'Mine', 'slug' => 'mine']);

    expect(tracking()->markAllRead($member))->toBe(2)
        ->and(ForumRead::where('user_id', $member->id)->pluck('forum_id')->sort()->values()->all())->toBe([$public->id, $private->id]);
});

it('erases a person\'s read markers and keeps everyone else\'s', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $forum = readForum();
    $thread = readThread($forum);
    foreach ([$alice, $bob] as $user) {
        tracking()->markThreadRead($user, $thread);
        tracking()->markForumsRead($user, [$forum->id]);
    }

    app(PrivacyContributorRegistry::class)->all()['forums']->erase($alice);

    expect(ThreadRead::pluck('user_id')->all())->toBe([$bob->id])->and(ForumRead::pluck('user_id')->all())->toBe([$bob->id]);
});
