<?php

declare(strict_types=1);

use ForumKit\Entitlements\Models\EntitlementDefinition;
use ForumKit\Entitlements\Models\UserEntitlementGrant;
use ForumKit\Forums\Contracts\PaidAccessCheck;
use ForumKit\Forums\Contracts\StaffPreview;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Enums\ForumPostingPolicy;
use ForumKit\Forums\Enums\ForumVisibility;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\ForumGroup;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Services\ForumAccess;
use ForumKit\Forums\Support\Abilities;
use ForumKit\Forums\Tests\Support\TestUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/*
 * The viewers every rule is checked against, in this order in every table:
 *   guest      not signed in
 *   member     signed in, holds nothing
 *   entitled   holds the entitlement `club.access`
 *   paid       the host's PaidAccessCheck says yes (no entitlement)
 *   author     has started a thread in the forum
 *   staff      holds forumkit.forums.moderate
 *   staff_off  the same, with the staff preview switched off
 */
const VIEWERS = ['guest', 'member', 'entitled', 'paid', 'author', 'staff', 'staff_off'];

beforeEach(function () {
    $this->paidIds = [];
    $this->previewOff = [];

    $this->paidDouble = new class($this)
    {
        public function __construct(private object $test) {}
    };

    app()->instance(PaidAccessCheck::class, new class($this) implements PaidAccessCheck
    {
        public function __construct(private object $test) {}

        public function hasPaidAccess(Authenticatable $user): bool
        {
            return in_array($user->getAuthIdentifier(), $this->test->paidIds, true);
        }
    });

    app()->instance(StaffPreview::class, new class($this) implements StaffPreview
    {
        public function __construct(private object $test) {}

        public function enabledFor(?Authenticatable $user): bool
        {
            return $user === null || ! in_array($user->getAuthIdentifier(), $this->test->previewOff, true);
        }
    });

    app()->forgetInstance(ForumAccess::class);
});

function accessForum(array $attributes = []): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    // Reloaded, so the database defaults (active, public, registered...) are on the model, as they
    // are for a forum read from the database.
    return Forum::create(array_merge([
        'forum_group_id' => $group->id,
        'name' => 'Forum '.uniqid(),
        'slug' => 'forum-'.uniqid(),
    ], $attributes))->refresh();
}

function accessThread(Forum $forum, TestUser $author, array $attributes = []): Thread
{
    return Thread::create(array_merge([
        'forum_id' => $forum->id,
        'author_id' => $author->id,
        'title' => 'Thread',
        'slug' => 'thread-'.uniqid(),
    ], $attributes))->refresh();
}

function give(TestUser $user, string $entitlement): TestUser
{
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $entitlement, 'grant_type' => 'manual']);

    return $user;
}

/**
 * The seven viewers for a forum, created fresh. The `author` has started a thread in it.
 *
 * @return array<string, TestUser|null>
 */
function viewers(Forum $forum, bool $authorStartsThread = true): array
{
    $test = test();
    $author = TestUser::make('Author');
    $staff = give(TestUser::make('Staff'), Abilities::MODERATE);
    $staffOff = give(TestUser::make('StaffOff'), Abilities::MODERATE);
    $paid = TestUser::make('Paid');

    $test->paidIds = [$paid->id];
    $test->previewOff = [$staffOff->id];

    if ($authorStartsThread) {
        accessThread($forum, $author);
    }

    return [
        'guest' => null,
        'member' => TestUser::make('Member'),
        'entitled' => give(TestUser::make('Entitled'), 'club.access'),
        'paid' => $paid,
        'author' => $author,
        'staff' => $staff,
        'staff_off' => $staffOff,
    ];
}

/**
 * @param  callable(?TestUser): bool  $check
 * @return array<string, bool>
 */
function resultsFor(array $viewers, callable $check): array
{
    $results = [];

    foreach ($viewers as $name => $user) {
        $results[$name] = $check($user);
    }

    return $results;
}

function expectation(string $pattern): array
{
    return array_combine(VIEWERS, array_map(fn (string $c): bool => $c === 'T', str_split($pattern)));
}

// ----------------------------------------------------------------- can view a forum

dataset('forum visibility', [
    //                                          guest member entitled paid author staff staff_off
    'public' => [ForumVisibility::PUBLIC, null, 'TTTTTTT'],
    'registered' => [ForumVisibility::REGISTERED, null, 'FTTTTTT'],
    'staff only' => [ForumVisibility::STAFF_ONLY, null, 'FFFFFTF'],
    'entitlement only, entitlement named' => [ForumVisibility::ENTITLEMENT_ONLY, 'club.access', 'FFTFFTF'],
    'entitlement only, none named' => [ForumVisibility::ENTITLEMENT_ONLY, null, 'FFFFFTF'],
    'paid only, entitlement named' => [ForumVisibility::PAID_ONLY, 'club.access', 'FFTFFTF'],
    'paid only, none named (membership)' => [ForumVisibility::PAID_ONLY, null, 'FFFTFTF'],
    'paid or author' => [ForumVisibility::PAID_OR_AUTHOR, null, 'FTTTTTT'],
    'staff or author' => [ForumVisibility::STAFF_AUTHOR_ONLY, null, 'FFFFFTF'],
]);

it('decides who can view a forum from its visibility', function (ForumVisibility $visibility, ?string $scope, string $pattern) {
    $forum = accessForum(['visibility' => $visibility, 'required_entitlement_scope' => $scope]);
    $viewers = viewers($forum);

    // The author viewer started a thread here, which only matters for staff-or-author forums.
    $expected = expectation($pattern);

    if ($visibility === ForumVisibility::STAFF_AUTHOR_ONLY) {
        $expected['author'] = true;
    }

    expect(resultsFor($viewers, fn ($user) => app(ForumAccess::class)->canViewForum($forum, $user)))->toBe($expected);
})->with('forum visibility');

it('never shows an inactive forum, not even to staff', function () {
    $forum = accessForum(['visibility' => ForumVisibility::PUBLIC, 'is_active' => false]);

    expect(resultsFor(viewers($forum), fn ($user) => app(ForumAccess::class)->canViewForum($forum, $user)))
        ->toBe(expectation('FFFFFFF'));
});

it('shows a staff-or-author forum that is empty to any member when it asks to be shown empty', function () {
    $forum = accessForum(['visibility' => ForumVisibility::STAFF_AUTHOR_ONLY, 'show_when_empty' => true]);
    $viewers = viewers($forum, authorStartsThread: false);

    expect(resultsFor($viewers, fn ($user) => app(ForumAccess::class)->canViewForum($forum, $user)))
        ->toBe(expectation('FTTTTTT'));

    // Starting a thread bumps the forum's stored counter (thread creation does this); "empty"
    // is judged from that counter.
    accessThread($forum, $viewers['author']);
    $forum->update(['thread_count' => 1]);
    app()->forgetInstance(ForumAccess::class);

    expect(resultsFor($viewers, fn ($user) => app(ForumAccess::class)->canViewForum($forum->refresh(), $user)))
        ->toBe(expectation('FFFFTTF'));
});

it('works out whether a forum is empty from the counts on the model or from the database', function () {
    $forum = accessForum(['visibility' => ForumVisibility::STAFF_AUTHOR_ONLY, 'show_when_empty' => true]);
    $member = TestUser::make();

    expect(app(ForumAccess::class)->canViewForum($forum, $member))->toBeTrue();

    accessThread($forum, TestUser::make('Other'));

    // A forum loaded with only some columns has no stored counter, so the database is asked.
    $partial = Forum::query()->select(['id', 'visibility', 'is_active', 'show_when_empty'])->find($forum->id);

    expect(app(ForumAccess::class)->canViewForum($partial, $member))->toBeFalse()
        ->and(app(ForumAccess::class)->canViewForum(Forum::query()->withCount('threads')->find($forum->id), $member))->toBeFalse();
});

// ---------------------------------------------------------------- can open a thread

dataset('thread access', [
    //                                            guest member entitled paid author staff staff_off
    'approved in a public forum' => [ForumVisibility::PUBLIC, null, ContentModerationStatus::APPROVED, 'TTTTTTT'],
    'pending in a public forum' => [ForumVisibility::PUBLIC, null, ContentModerationStatus::PENDING, 'FFFFTTF'],
    'rejected in a public forum' => [ForumVisibility::PUBLIC, null, ContentModerationStatus::REJECTED, 'FFFFTTF'],
    'permanently rejected' => [ForumVisibility::PUBLIC, null, ContentModerationStatus::REJECTED_FINAL, 'FFFFTTF'],
    'approved in a staff-or-author forum' => [ForumVisibility::STAFF_AUTHOR_ONLY, null, ContentModerationStatus::APPROVED, 'FFFFTTF'],
    'approved in a paid-or-author forum (membership)' => [ForumVisibility::PAID_OR_AUTHOR, null, ContentModerationStatus::APPROVED, 'FFFTTTF'],
    'approved in a paid-or-author forum (entitlement named)' => [ForumVisibility::PAID_OR_AUTHOR, 'club.access', ContentModerationStatus::APPROVED, 'FFTFTTF'],
    'approved in a registered forum' => [ForumVisibility::REGISTERED, null, ContentModerationStatus::APPROVED, 'TTTTTTT'],
]);

it('decides who can open a thread from its status and its forum', function (ForumVisibility $visibility, ?string $scope, ContentModerationStatus $status, string $pattern) {
    $forum = accessForum(['visibility' => $visibility, 'required_entitlement_scope' => $scope]);
    $viewers = viewers($forum, authorStartsThread: false);
    $thread = accessThread($forum, $viewers['author'], ['moderation_status' => $status]);

    expect(resultsFor($viewers, fn ($user) => app(ForumAccess::class)->canAccessThread($thread, $user)))->toBe(expectation($pattern));
})->with('thread access');

// ------------------------------------------------------------- can post in a forum

dataset('posting policy', [
    //                                              guest member entitled paid author staff staff_off
    'public' => [ForumPostingPolicy::PUBLIC, null, 'FTTTTTT'],
    'registered' => [ForumPostingPolicy::REGISTERED, null, 'FTTTTTT'],
    'staff only' => [ForumPostingPolicy::STAFF_ONLY, null, 'FFFFFTF'],
    'staff or author' => [ForumPostingPolicy::STAFF_AUTHOR_ONLY, null, 'FFFFTTF'],
    'entitlement only, entitlement named' => [ForumPostingPolicy::ENTITLEMENT_ONLY, 'club.access', 'FFTFFTF'],
    'entitlement only, none named' => [ForumPostingPolicy::ENTITLEMENT_ONLY, null, 'FFFFFTF'],
]);

it('decides who can post in a forum from its posting policy', function (ForumPostingPolicy $policy, ?string $scope, string $pattern) {
    $forum = accessForum(['posting_policy' => $policy, 'reply_entitlement_scope' => $scope]);

    expect(resultsFor(viewers($forum), fn ($user) => app(ForumAccess::class)->canPostInForum($forum, $user)))->toBe(expectation($pattern));
})->with('posting policy');

it('lets nobody post in a read-only or inactive forum, not even staff', function () {
    foreach ([['is_read_only' => true], ['is_active' => false]] as $closed) {
        $forum = accessForum(array_merge(['posting_policy' => ForumPostingPolicy::PUBLIC], $closed));

        expect(resultsFor(viewers($forum), fn ($user) => app(ForumAccess::class)->canPostInForum($forum, $user)))->toBe(expectation('FFFFFFF'));
    }
});

// ------------------------------------------------------------ can reply to a thread

dataset('reply rules', [
    //                                           guest member entitled paid author staff staff_off
    'no reply entitlement' => [ForumPostingPolicy::REGISTERED, null, 'FTTTTTT'],
    'reply entitlement named (author exempt)' => [ForumPostingPolicy::REGISTERED, 'club.access', 'FFTFTTF'],
    'staff-only forum (even the thread author cannot reply)' => [ForumPostingPolicy::STAFF_ONLY, null, 'FFFFFTF'],
    'entitlement-only posting' => [ForumPostingPolicy::ENTITLEMENT_ONLY, 'club.access', 'FFTFFTF'],
]);

it('decides who can reply to a thread', function (ForumPostingPolicy $policy, ?string $scope, string $pattern) {
    $forum = accessForum(['posting_policy' => $policy, 'reply_entitlement_scope' => $scope]);
    $viewers = viewers($forum, authorStartsThread: false);
    $thread = accessThread($forum, $viewers['author']);

    // `entitlement only` is the one case where the thread's author gets no exemption: the
    // entitlement is needed to post at all.
    expect(resultsFor($viewers, fn ($user) => app(ForumAccess::class)->canReplyToThread($thread->load('forum'), $user)))->toBe(expectation($pattern));
})->with('reply rules');

// ------------------------------------------------------------------- staff preview

it('treats staff as ordinary members while their preview is off', function () {
    $forum = accessForum(['visibility' => ForumVisibility::STAFF_ONLY, 'posting_policy' => ForumPostingPolicy::STAFF_ONLY]);
    $staff = give(TestUser::make('Staff'), Abilities::VIEW_UNAPPROVED);
    $access = app(ForumAccess::class);

    expect($access->isStaff($staff))->toBeTrue()
        ->and($access->actsAsStaff($staff))->toBeTrue()
        ->and($access->canViewForum($forum, $staff))->toBeTrue();

    $this->previewOff = [$staff->id];

    expect($access->isStaff($staff))->toBeTrue()
        ->and($access->actsAsStaff($staff))->toBeFalse()
        ->and($access->canViewForum($forum, $staff))->toBeFalse()
        ->and($access->canPostInForum($forum, $staff))->toBeFalse();
});

it('counts any one of the three staff entitlements as staff, and nothing else', function () {
    $access = app(ForumAccess::class);

    foreach (Abilities::staff() as $ability) {
        expect($access->isStaff(give(TestUser::make(), $ability)))->toBeTrue($ability);
    }

    expect($access->isStaff(TestUser::make()))->toBeFalse()
        ->and($access->isStaff(null))->toBeFalse()
        ->and($access->isStaff(give(TestUser::make(), 'forumkit.entitlements.manage')))->toBeFalse();
});

// ----------------------------------------------------------- what a viewer sees

it('works out which forums a viewer sees in full, which only their own threads in, and which not at all', function () {
    $public = accessForum(['visibility' => ForumVisibility::PUBLIC]);
    $registered = accessForum(['visibility' => ForumVisibility::REGISTERED]);
    $staffOnly = accessForum(['visibility' => ForumVisibility::STAFF_ONLY]);
    $paidOrAuthor = accessForum(['visibility' => ForumVisibility::PAID_OR_AUTHOR]);
    $own = accessForum(['visibility' => ForumVisibility::STAFF_AUTHOR_ONLY]);
    $inactive = accessForum(['visibility' => ForumVisibility::PUBLIC, 'is_active' => false]);
    $inactiveGroup = accessForum(['visibility' => ForumVisibility::PUBLIC, 'forum_group_id' => ForumGroup::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false])->id]);

    $member = TestUser::make('Member');
    $paid = TestUser::make('Paid');
    $this->paidIds = [$paid->id];
    accessThread($own, $member);
    $staff = give(TestUser::make('Staff'), Abilities::MODERATE);
    $access = app(ForumAccess::class);

    $guestScope = $access->forumScopeFor(null);
    $memberScope = $access->forumScopeFor($member);
    $paidScope = $access->forumScopeFor($paid);
    $staffScope = $access->forumScopeFor($staff);

    expect($guestScope->unrestricted)->toBeFalse()
        ->and($guestScope->fullForumIds)->toBe([$public->id])
        ->and($guestScope->ownThreadsForumIds)->toBe([])
        ->and($memberScope->fullForumIds)->toBe([$public->id, $registered->id])
        ->and($memberScope->ownThreadsForumIds)->toBe([$paidOrAuthor->id, $own->id])
        ->and($paidScope->fullForumIds)->toBe([$public->id, $registered->id, $paidOrAuthor->id])
        ->and($paidScope->ownThreadsForumIds)->toBe([])
        ->and($staffScope->unrestricted)->toBeTrue()
        ->and([$staffOnly->id, $inactive->id, $inactiveGroup->id])->each->not->toBeIn([...$memberScope->fullForumIds, ...$memberScope->ownThreadsForumIds]);
});

it('says in which forums a viewer sees only their own threads', function () {
    $paidOrAuthor = accessForum(['visibility' => ForumVisibility::PAID_OR_AUTHOR]);
    $ownOnly = accessForum(['visibility' => ForumVisibility::STAFF_AUTHOR_ONLY]);
    $public = accessForum(['visibility' => ForumVisibility::PUBLIC]);
    $paid = TestUser::make('Paid');
    $this->paidIds = [$paid->id];
    $staff = give(TestUser::make(), Abilities::MODERATE);
    $access = app(ForumAccess::class);

    expect($access->seesOnlyOwnThreads($paidOrAuthor, null))->toBeTrue()
        ->and($access->seesOnlyOwnThreads($paidOrAuthor, TestUser::make()))->toBeTrue()
        ->and($access->seesOnlyOwnThreads($paidOrAuthor, $paid))->toBeFalse()
        ->and($access->seesOnlyOwnThreads($ownOnly, $paid))->toBeTrue()
        ->and($access->seesOnlyOwnThreads($public, TestUser::make()))->toBeFalse()
        ->and($access->seesOnlyOwnThreads($ownOnly, $staff))->toBeFalse();
});

it('shows approved content, and a viewer\'s own whatever its status', function () {
    $forum = accessForum();
    $me = TestUser::make('Me');
    $other = TestUser::make('Other');
    $approved = accessThread($forum, $other, ['title' => 'approved']);
    $myPending = accessThread($forum, $me, ['title' => 'my pending', 'moderation_status' => ContentModerationStatus::PENDING]);
    accessThread($forum, $other, ['title' => 'their pending', 'moderation_status' => ContentModerationStatus::PENDING]);
    accessThread($forum, $other, ['title' => 'their rejected', 'moderation_status' => ContentModerationStatus::REJECTED]);
    Post::create(['thread_id' => $approved->id, 'author_id' => $other->id, 'body' => 'ok']);
    $myDraft = Post::create(['thread_id' => $approved->id, 'author_id' => $me->id, 'body' => 'mine', 'moderation_status' => ContentModerationStatus::PENDING]);
    Post::create(['thread_id' => $approved->id, 'author_id' => $other->id, 'body' => 'theirs', 'moderation_status' => ContentModerationStatus::PENDING]);
    $access = app(ForumAccess::class);

    expect($access->scopeVisibleThreads(Thread::query(), $me)->orderBy('id')->pluck('title')->all())->toBe(['approved', 'my pending'])
        ->and($access->scopeVisibleThreads(Thread::query(), null)->pluck('title')->all())->toBe(['approved'])
        ->and($access->scopeVisiblePosts(Post::query(), $me)->orderBy('id')->pluck('body')->all())->toBe(['ok', 'mine'])
        ->and($access->scopeVisiblePosts(Post::query(), null)->pluck('body')->all())->toBe(['ok'])
        ->and($myPending->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and($myDraft->isPending())->toBeTrue();
});

it('remembers the forums a user authored in for the request, until told to forget', function () {
    $a = accessForum();
    $b = accessForum();
    $user = TestUser::make();
    accessThread($a, $user);
    $access = app(ForumAccess::class);

    expect($access->authoredForumIds($user))->toBe([$a->id])
        ->and($access->authoredForumIds(null))->toBe([]);

    accessThread($b, $user);

    expect($access->authoredForumIds($user))->toBe([$a->id]);

    $access->flush();

    expect($access->authoredForumIds($user))->toBe([$a->id, $b->id]);
});

// -------------------------------------------------------- abilities and entitlements

it('gates the staff abilities on the mapped entitlements', function () {
    $user = TestUser::make();

    foreach (Abilities::staff() as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse($ability);

        give($user, $ability);

        expect(Gate::forUser($user)->allows($ability))->toBeTrue($ability);
    }

    expect(Gate::allows(Abilities::MODERATE))->toBeFalse();
});

it('creates definitions for the staff entitlements and for the keys set on forums', function () {
    accessForum(['required_entitlement_scope' => 'club.access', 'reply_entitlement_scope' => ' club.post ']);
    accessForum(['required_entitlement_scope' => 'club.access']);
    accessForum(['reply_entitlement_scope' => '  ']);

    $this->artisan('forumkit:entitlements:sync')->assertExitCode(0);

    $keys = EntitlementDefinition::pluck('key')->all();

    expect($keys)->toContain('club.access', 'club.post', ...Abilities::staff())
        ->and(array_count_values($keys)['club.access'])->toBe(1);
});

// ----------------------------------------------------- a scenario from the live forum

it('lets a member into a paid-or-author forum to start threads that only they and staff can see', function () {
    // A "commercial help wanted" forum: visible to every signed-in user, but each member sees only
    // their own threads unless they hold the entitlement, which is also needed to reply to others.
    $forum = accessForum([
        'visibility' => ForumVisibility::PAID_OR_AUTHOR,
        'posting_policy' => ForumPostingPolicy::REGISTERED,
        'required_entitlement_scope' => 'club.access',
        'reply_entitlement_scope' => 'club.access',
    ]);
    $member = TestUser::make('Member');
    $other = TestUser::make('Other');
    $othersThread = accessThread($forum, $other, ['title' => 'Another customer request'])->load('forum');
    $access = app(ForumAccess::class);

    expect($access->canViewForum($forum, $member))->toBeTrue()
        ->and($access->seesOnlyOwnThreads($forum, $member))->toBeTrue()
        ->and($access->canPostInForum($forum, $member))->toBeTrue()
        ->and($access->canAccessThread($othersThread, $member))->toBeFalse()
        ->and($access->canReplyToThread($othersThread, $member))->toBeFalse();

    $mine = accessThread($forum, $member, ['title' => 'My request'])->load('forum');

    expect($access->canAccessThread($mine, $member))->toBeTrue()
        ->and($access->canReplyToThread($mine, $member))->toBeTrue()
        ->and($access->canAccessThread($mine, $other))->toBeFalse();

    give($member, 'club.access');
    $access->flush();

    expect($access->seesOnlyOwnThreads($forum, $member))->toBeFalse()
        ->and($access->canAccessThread($othersThread, $member))->toBeTrue()
        ->and($access->canReplyToThread($othersThread, $member))->toBeTrue();
});
