<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Events\ForumSubscribersToNotify;
use Filagora\Forums\Events\ThreadCreated;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Events\ThreadPromoted;
use Filagora\Forums\Jobs\AnnounceThreadToForumSubscribers;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\ForumSubscription;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadSubscription;
use Filagora\Forums\Notifications\ForumThreadCreatedNotification;
use Filagora\Forums\Services\ForumSubscriptions;
use Filagora\Forums\Services\PostRelocation;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

function forumSubs(): ForumSubscriptions
{
    return app(ForumSubscriptions::class);
}

function subForum(ForumVisibility $visibility = ForumVisibility::PUBLIC): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(['forum_group_id' => $group->id, 'name' => 'General '.uniqid(), 'slug' => 'f-'.uniqid(), 'visibility' => $visibility])->refresh();
}

function subForumThread(Forum $forum, TestUser $author, array $attributes = []): Thread
{
    return Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'A new thread', 'slug' => 't-'.uniqid()], $attributes))->refresh();
}

/**
 * @return list<int|string> the ids handed to the event
 */
function announcedTo(Thread $thread): array
{
    $ids = [];
    Event::fake([ForumSubscribersToNotify::class]);
    forumSubs()->announce($thread);
    Event::assertDispatched(ForumSubscribersToNotify::class, function (ForumSubscribersToNotify $event) use (&$ids): bool {
        array_push($ids, ...array_map(fn ($user) => $user->getAuthIdentifier(), $event->recipients));

        return true;
    });

    return $ids;
}

it('follows and unfollows a forum, once', function () {
    $forum = subForum();
    $other = subForum();
    $user = TestUser::make('Member');

    forumSubs()->subscribe($user, $forum);
    forumSubs()->subscribe($user, $forum);

    expect(ForumSubscription::query()->count())->toBe(1)
        ->and(forumSubs()->isSubscribed($user, $forum))->toBeTrue()
        ->and(forumSubs()->isSubscribed(null, $forum))->toBeFalse()
        ->and(forumSubs()->followedAmong($user, [$forum->id, $other->id]))->toBe([$forum->id])
        ->and(forumSubs()->forumsFollowedBy($user)->pluck('id')->all())->toBe([$forum->id]);

    forumSubs()->unsubscribe($user, $forum);

    expect(forumSubs()->isSubscribed($user, $forum))->toBeFalse();
});

it('announces a thread to followers, leaving out its author and anyone already following the thread', function () {
    $forum = subForum();
    $author = TestUser::make('Author');
    $follower = TestUser::make('Follower');
    $alreadyInThread = TestUser::make('Already');
    $thread = subForumThread($forum, $author);

    foreach ([$author, $follower, $alreadyInThread] as $user) {
        forumSubs()->subscribe($user, $forum);
    }
    ThreadSubscription::create(['thread_id' => $thread->id, 'user_id' => $alreadyInThread->id]);

    expect(announcedTo($thread))->toBe([$follower->id]);
});

it('tells nobody about a thread that is not approved', function () {
    $forum = subForum();
    $thread = subForumThread($forum, TestUser::make('Author'), ['moderation_status' => ContentModerationStatus::PENDING]);
    forumSubs()->subscribe(TestUser::make('Follower'), $forum);
    Event::fake([ForumSubscribersToNotify::class]);

    expect(forumSubs()->announce($thread))->toBe(0);
    Event::assertNotDispatched(ForumSubscribersToNotify::class);
});

it('stops telling a follower who can no longer read the forum', function () {
    $forum = subForum(ForumVisibility::STAFF_ONLY);
    $thread = subForumThread($forum, TestUser::make('Author'));
    forumSubs()->subscribe(TestUser::make('Follower'), $forum);
    Event::fake([ForumSubscribersToNotify::class]);

    expect(forumSubs()->announce($thread))->toBe(0);
    Event::assertNotDispatched(ForumSubscribersToNotify::class);
});

it('queues the news when a thread appears, and not otherwise', function () {
    $forum = subForum();
    $author = TestUser::make('Author');
    $thread = subForumThread($forum, $author);
    $post = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'First'])->refresh();
    Bus::fake([AnnounceThreadToForumSubscribers::class]);

    event(new ThreadCreated($thread, $post, requiresApproval: true, subscribeAuthor: false));
    event(new ThreadModerated($thread, $author, 'lock', null));
    Bus::assertNotDispatched(AnnounceThreadToForumSubscribers::class);

    event(new ThreadCreated($thread, $post, requiresApproval: false, subscribeAuthor: false));
    event(new ThreadModerated($thread, $author, 'approve', null));
    event(new ThreadModerated($thread, $author, 'move', null));
    event(new ThreadPromoted($thread, $author));
    Bus::assertDispatchedTimes(AnnounceThreadToForumSubscribers::class, 4);
});

it('does not queue the news for a thread still waiting for approval', function () {
    $thread = subForumThread(subForum(), $author = TestUser::make('Author'), ['moderation_status' => ContentModerationStatus::PENDING]);
    Bus::fake([AnnounceThreadToForumSubscribers::class]);

    event(new ThreadModerated($thread, $author, 'move', null));

    Bus::assertNotDispatched(AnnounceThreadToForumSubscribers::class);
});

it('says so when a reply is made into a thread of its own', function () {
    $author = TestUser::make('Author');
    $thread = subForumThread(subForum(), $author);
    Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'First'])->refresh();
    $reply = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'A reply worth its own thread'])->refresh();
    $destination = subForum();
    $moderator = TestUser::make('Mod');
    UserEntitlementGrant::create(['user_id' => $moderator->id, 'entitlement_key' => Abilities::MODERATE, 'grant_type' => 'manual']);
    Event::fake([ThreadPromoted::class]);

    $promoted = app(PostRelocation::class)->promote($moderator, $reply, $destination, 'Its own thread');

    Event::assertDispatched(ThreadPromoted::class, fn (ThreadPromoted $event): bool => $event->thread->is($promoted));
});

it('mails followers by default and stays silent when subscription mail is off', function () {
    $forum = subForum();
    $thread = subForumThread($forum, TestUser::make('Author'));
    $follower = TestUser::make('Follower');
    forumSubs()->subscribe($follower, $forum);
    Notification::fake();

    forumSubs()->announce($thread);
    Notification::assertSentTo($follower, ForumThreadCreatedNotification::class);

    Notification::fake();
    config(['filagora-forums.subscriptions.mail.enabled' => false]);
    forumSubs()->announce($thread);
    Notification::assertNothingSent();
});

it('lists a person\'s forum subscriptions in the export and removes them on erasure', function () {
    $forum = subForum();
    $user = TestUser::make('Member');
    forumSubs()->subscribe($user, $forum);
    $contributor = collect(app(PrivacyContributorRegistry::class)->all())->first(fn ($item) => $item->key() === 'forums');

    expect(array_column($contributor->export($user)['forum_subscriptions'], 'forum_id'))->toBe([$forum->id]);

    $contributor->erase($user);

    expect(ForumSubscription::query()->count())->toBe(0);
});
