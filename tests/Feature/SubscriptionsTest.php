<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Forums\Contracts\SubscriptionPreferences;
use Filagora\Forums\Enums\DeliveryMode;
use Filagora\Forums\Events\SubscribersToNotify;
use Filagora\Forums\Events\ThreadDigestReady;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadSubscription;
use Filagora\Forums\Models\ThreadSubscriptionDigestItem;
use Filagora\Forums\Notifications\ThreadDigestNotification;
use Filagora\Forums\Notifications\ThreadUpdatedNotification;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\Posting;
use Filagora\Forums\Services\Subscriptions;
use Filagora\Forums\Subscriptions\UserAttributePreferences;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

function subs(): Subscriptions
{
    return app(Subscriptions::class);
}

function subThread(?TestUser $author = null): Thread
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::query()->firstOrCreate(['slug' => 'general'], ['forum_group_id' => $group->id, 'name' => 'General']);

    return Thread::create(['forum_id' => $forum->id, 'author_id' => ($author ?? TestUser::make('Author'))->id, 'title' => 'Hello thread', 'slug' => 't-'.uniqid()])->refresh();
}

function subPost(Thread $thread, TestUser $author, string $body = 'A reply'): Post
{
    return Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => $body])->refresh();
}

/** A follower with a delivery mode; the default preferences read the user attribute. */
function follower(Thread $thread, string $mode = 'instant'): TestUser
{
    $user = TestUser::make('Follower');
    $user->forceFill(['thread_subscription_email_frequency' => $mode])->save();
    subs()->subscribe($user, $thread);

    return $user;
}

beforeEach(function () {
    Notification::fake();
});

// ------------------------------------------------------------------ following

it('follows and unfollows a thread, once however often asked', function () {
    $thread = subThread();
    $user = TestUser::make('Reader');

    expect(subs()->isSubscribed($user, $thread))->toBeFalse();

    subs()->subscribe($user, $thread);
    subs()->subscribe($user, $thread);

    expect(subs()->isSubscribed($user, $thread))->toBeTrue()
        ->and(ThreadSubscription::count())->toBe(1);

    subs()->unsubscribe($user, $thread);
    subs()->unsubscribe($user, $thread);

    expect(subs()->isSubscribed($user, $thread))->toBeFalse()->and(subs()->isSubscribed(null, $thread))->toBeFalse();
});

it('says which of many threads a person follows', function () {
    [$one, $two, $three] = [subThread(), subThread(), subThread()];
    $user = TestUser::make('Reader');
    subs()->subscribe($user, $one);
    subs()->subscribe($user, $three);
    subs()->subscribe(TestUser::make('Other'), $two);

    expect(subs()->followedAmong($user, [$one->id, $two->id, $three->id]))->toEqualCanonicalizing([$one->id, $three->id])
        ->and(subs()->followedAmong($user, []))->toBe([])
        ->and(subs()->followedAmong(null, [$one->id]))->toBe([]);
});

it('reads a person\'s preferences from their user attributes, with safe defaults', function () {
    $prefs = app(SubscriptionPreferences::class);
    $user = TestUser::make('Reader');

    expect($prefs)->toBeInstanceOf(UserAttributePreferences::class)
        ->and($prefs->deliveryMode($user))->toBe(DeliveryMode::INSTANT)
        ->and($prefs->autoSubscribeOnPost($user))->toBeFalse();

    $user->forceFill(['thread_subscription_email_frequency' => ' Weekly ', 'auto_subscribe_on_post' => 1]);
    expect($prefs->deliveryMode($user))->toBe(DeliveryMode::WEEKLY)->and($prefs->autoSubscribeOnPost($user))->toBeTrue();

    $user->forceFill(['thread_subscription_email_frequency' => 'nonsense', 'auto_subscribe_on_post' => 0]);
    expect($prefs->deliveryMode($user))->toBe(DeliveryMode::INSTANT)->and($prefs->autoSubscribeOnPost($user))->toBeFalse();

    config(['filagora-forums.subscriptions.auto_subscribe_default' => true]);
    expect($prefs->autoSubscribeOnPost(TestUser::make('Fresh')))->toBeTrue();
});

it('lets a say-so on this post beat the standing preference', function () {
    $user = TestUser::make('Reader');
    $user->forceFill(['auto_subscribe_on_post' => true]);

    expect(subs()->shouldFollowOnPost($user))->toBeTrue()
        ->and(subs()->shouldFollowOnPost($user, false))->toBeFalse()
        ->and(subs()->shouldFollowOnPost(TestUser::make('Other'), true))->toBeTrue();
});

// ------------------------------------------------------------------ announcing

it('hands instant followers to an event, queues digest followers, and skips the author and those who turned it off', function () {
    Event::fake([SubscribersToNotify::class]);
    $thread = subThread();
    $author = TestUser::make('Poster');
    subs()->subscribe($author, $thread);
    $instant = follower($thread, 'instant');
    $daily = follower($thread, 'daily');
    $weekly = follower($thread, 'weekly');
    $off = follower($thread, 'off');
    $post = subPost($thread, $author);

    subs()->announce($post);

    Event::assertDispatched(SubscribersToNotify::class, fn ($e) => $e->post->is($post) && count($e->recipients) === 1 && $e->recipients[0]->getKey() === $instant->id);
    expect(ThreadSubscriptionDigestItem::orderBy('user_id')->pluck('user_id')->all())->toBe([$daily->id, $weekly->id])
        ->and(ThreadSubscriptionDigestItem::whereNull('sent_at')->count())->toBe(2)
        ->and(ThreadSubscriptionDigestItem::where('user_id', $author->id)->orWhere('user_id', $off->id)->count())->toBe(0);
});

it('queues a digest row once per person and post even if announced twice', function () {
    $thread = subThread();
    follower($thread, 'daily');
    $post = subPost($thread, TestUser::make('Poster'));

    subs()->announce($post);
    subs()->announce($post);

    expect(ThreadSubscriptionDigestItem::count())->toBe(1);
});

it('says nothing when there is no one to tell', function () {
    Event::fake([SubscribersToNotify::class]);
    $thread = subThread();

    subs()->announce(subPost($thread, TestUser::make('Poster')));

    Event::assertNotDispatched(SubscribersToNotify::class);
    expect(ThreadSubscriptionDigestItem::count())->toBe(0);
});

// ------------------------------------------------------------------ posting integration

it('follows a thread you start, unless you asked not to', function () {
    app()->forgetInstance(ForumAccess::class);
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'General', 'slug' => 'general-2'])->refresh();
    $a = TestUser::make('A');
    $b = TestUser::make('B');

    $first = app(Posting::class)->startThread($a, $forum, 'My topic', 'Body of the topic');
    $second = app(Posting::class)->startThread($b, $forum, 'Other topic', 'Body of the other', subscribeAuthor: false);

    expect(subs()->isSubscribed($a, $first->thread))->toBeTrue()
        ->and(subs()->isSubscribed($b, $second->thread))->toBeFalse();
});

it('does not follow a thread you started with a no, even if you usually follow what you post in', function () {
    app()->forgetInstance(ForumAccess::class);
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'General', 'slug' => 'general-3'])->refresh();
    $author = TestUser::make('Usually follows');
    $author->forceFill(['auto_subscribe_on_post' => true])->save();

    $created = app(Posting::class)->startThread($author, $forum, 'Quiet topic', 'Body of the quiet topic', subscribeAuthor: false);

    expect(subs()->isSubscribed($author, $created->thread))->toBeFalse();
});

it('mails followers about a reply, by default, and not the person who wrote it', function () {
    $thread = subThread();
    $reader = follower($thread);
    $author = TestUser::make('Replier');
    subs()->subscribe($author, $thread);
    app()->forgetInstance(ForumAccess::class);

    app(Posting::class)->reply($author, $thread, 'This is a reply');

    Notification::assertSentTo($reader, ThreadUpdatedNotification::class);
    Notification::assertNotSentTo($author, ThreadUpdatedNotification::class);
});

it('does not announce a reply that waits for approval, or a thread\'s first post', function () {
    Event::fake([SubscribersToNotify::class]);
    $thread = subThread();
    follower($thread);
    $replier = TestUser::make('Replier');
    $thread->forum->update(['requires_reply_approval' => true]);
    app()->forgetInstance(ForumAccess::class);

    app(Posting::class)->reply($replier, $thread, 'Held for review');

    Event::assertNotDispatched(SubscribersToNotify::class);
});

it('follows the thread when a reply\'s author prefers it', function () {
    $thread = subThread();
    $replier = TestUser::make('Replier');
    $replier->forceFill(['auto_subscribe_on_post' => true])->save();
    $quiet = TestUser::make('Quiet');
    app()->forgetInstance(ForumAccess::class);

    app(Posting::class)->reply($replier, $thread, 'Auto follow reply');
    app(Posting::class)->reply($quiet, $thread, 'No follow reply');

    expect(subs()->isSubscribed($replier, $thread))->toBeTrue()->and(subs()->isSubscribed($quiet, $thread))->toBeFalse();
});

it('sends no default mail when switched off, leaving the events to the host', function () {
    config(['filagora-forums.subscriptions.mail.enabled' => false]);
    $thread = subThread();
    follower($thread);
    app()->forgetInstance(ForumAccess::class);

    app(Posting::class)->reply(TestUser::make('Replier'), $thread, 'Quiet reply');

    Notification::assertNothingSent();
});

it('builds the update mail with the thread and a link when the host names the route', function () {
    Route::get('/t/{thread}', fn () => '')->name('threads.show');
    app('router')->getRoutes()->refreshNameLookups();
    config(['filagora-forums.subscriptions.routes.thread' => 'threads.show']);
    $thread = subThread();
    $reader = follower($thread);
    app()->forgetInstance(ForumAccess::class);

    app(Posting::class)->reply(TestUser::make('Replier'), $thread, '<b>Look</b> at this');

    Notification::assertSentTo($reader, ThreadUpdatedNotification::class, function ($n) use ($reader) {
        $mail = $n->toMail($reader);

        return str_contains($mail->subject, 'Hello thread') && in_array('Look at this', $mail->introLines, true) && str_contains((string) $mail->actionUrl, '/t/');
    });
});

// ------------------------------------------------------------------ digests

it('sends each person their due digest once, for the frequency they chose, and marks the items sent', function () {
    Event::fake([ThreadDigestReady::class]);
    $thread = subThread();
    $daily = follower($thread, 'daily');
    $weekly = follower($thread, 'weekly');
    $poster = TestUser::make('Poster');
    subs()->announce(subPost($thread, $poster, 'First'));
    subs()->announce(subPost($thread, $poster, 'Second'));

    $result = subs()->sendDigests(DeliveryMode::DAILY);

    expect($result)->toBe(['users' => 1, 'items' => 2])
        ->and(ThreadSubscriptionDigestItem::where('user_id', $daily->id)->whereNull('sent_at')->count())->toBe(0)
        ->and(ThreadSubscriptionDigestItem::where('user_id', $weekly->id)->whereNull('sent_at')->count())->toBe(2);
    Event::assertDispatchedTimes(ThreadDigestReady::class, 1);
    Event::assertDispatched(ThreadDigestReady::class, fn ($e) => $e->user->getKey() === $daily->id && $e->frequency === DeliveryMode::DAILY && count($e->items) === 2 && $e->items[0]->post->body === 'First');

    expect(subs()->sendDigests(DeliveryMode::DAILY))->toBe(['users' => 0, 'items' => 0]);
});

it('puts only the unsent items in a digest, never ones already sent', function () {
    Event::fake([ThreadDigestReady::class]);
    $thread = subThread();
    $reader = follower($thread, 'daily');
    $poster = TestUser::make('Poster');
    subs()->announce(subPost($thread, $poster, 'Old news'));
    subs()->sendDigests(DeliveryMode::DAILY);
    subs()->announce(subPost($thread, $poster, 'Fresh news'));

    subs()->sendDigests(DeliveryMode::DAILY);

    Event::assertDispatchedTimes(ThreadDigestReady::class, 2);
    Event::assertDispatched(ThreadDigestReady::class, fn ($e) => count($e->items) === 1 && $e->items[0]->post->body === 'Fresh news' && $e->user->getKey() === $reader->id);
});

it('reports a dry run without sending or marking anything', function () {
    Event::fake([ThreadDigestReady::class]);
    $thread = subThread();
    follower($thread, 'daily');
    subs()->announce(subPost($thread, TestUser::make('Poster')));

    expect(subs()->sendDigests(DeliveryMode::DAILY, dryRun: true))->toBe(['users' => 1, 'items' => 1])
        ->and(ThreadSubscriptionDigestItem::whereNull('sent_at')->count())->toBe(1);
    Event::assertNotDispatched(ThreadDigestReady::class);
});

it('skips a person who has since changed to another frequency', function () {
    $thread = subThread();
    $user = follower($thread, 'daily');
    subs()->announce(subPost($thread, TestUser::make('Poster')));

    app()->bind(SubscriptionPreferences::class, fn () => new class implements SubscriptionPreferences
    {
        public function deliveryMode(Authenticatable $user): DeliveryMode
        {
            return DeliveryMode::WEEKLY;
        }

        public function autoSubscribeOnPost(Authenticatable $user): bool
        {
            return false;
        }
    });
    app()->forgetInstance(Subscriptions::class);

    expect(app(Subscriptions::class)->sendDigests(DeliveryMode::DAILY))->toBe(['users' => 0, 'items' => 0])
        ->and(app(Subscriptions::class)->sendDigests(DeliveryMode::WEEKLY))->toBe(['users' => 1, 'items' => 1])
        ->and($user->exists)->toBeTrue();
});

it('emails the digest, grouped by thread, through the command', function () {
    $thread = subThread();
    $reader = follower($thread, 'daily');
    subs()->announce(subPost($thread, TestUser::make('Poster'), 'Digest body'));

    $this->artisan('filagora:forums:send-digest', ['frequency' => 'daily'])->expectsOutputToContain('Digest sent: users=1, items=1, frequency=daily')->assertExitCode(0);

    Notification::assertSentTo($reader, ThreadDigestNotification::class, function ($n) use ($reader) {
        $mail = $n->toMail($reader);

        return $mail->subject === 'Your daily thread digest' && in_array('**Hello thread** (1 new post)', $mail->introLines, true) && in_array('> Digest body', $mail->introLines, true);
    });
});

it('refuses a frequency that is not a digest', function () {
    $this->artisan('filagora:forums:send-digest', ['frequency' => 'hourly'])->expectsOutputToContain('Frequency must be "daily" or "weekly".')->assertExitCode(1);
    $this->artisan('filagora:forums:send-digest', ['frequency' => 'instant'])->assertExitCode(1);
});

it('reports a dry run through the command', function () {
    $thread = subThread();
    follower($thread, 'weekly');
    subs()->announce(subPost($thread, TestUser::make('Poster')));

    $this->artisan('filagora:forums:send-digest', ['frequency' => 'weekly', '--dry-run' => true])->expectsOutputToContain('Digest dry-run: users=1, items=1, frequency=weekly')->assertExitCode(0);

    Notification::assertNothingSent();
});

// ------------------------------------------------------------------ housekeeping

it('prunes old digest items by the retention window', function () {
    $thread = subThread();
    $user = follower($thread, 'daily');
    $post = subPost($thread, TestUser::make('Poster'));
    $other = subPost($thread, TestUser::make('Poster2'));
    $old = ThreadSubscriptionDigestItem::create(['user_id' => $user->id, 'thread_id' => $thread->id, 'post_id' => $post->id]);
    $new = ThreadSubscriptionDigestItem::create(['user_id' => $user->id, 'thread_id' => $thread->id, 'post_id' => $other->id]);
    $old->newQuery()->whereKey($old->id)->toBase()->update(['created_at' => now()->subDays(400)->setTime(12, 0)]);
    $rule = app(RetentionRuleRegistry::class)->all()['forums.digest_items'];

    expect($rule->prune(true))->toBe(1)->and($rule->prune(false))->toBe(1)->and(ThreadSubscriptionDigestItem::pluck('id')->all())->toBe([$new->id]);
});

it('exports and erases a person\'s subscriptions and digest queue', function () {
    $thread = subThread();
    $user = follower($thread, 'daily');
    $other = follower($thread, 'daily');
    subs()->announce(subPost($thread, TestUser::make('Poster')));
    $contributor = app(PrivacyContributorRegistry::class)->all()['forums'];

    $export = $contributor->export($user);
    expect($export['thread_subscriptions'])->toHaveCount(1)->and($export['digest_items'])->toHaveCount(1);

    $contributor->erase($user);

    expect(ThreadSubscription::where('user_id', $user->id)->count())->toBe(0)
        ->and(ThreadSubscriptionDigestItem::where('user_id', $user->id)->count())->toBe(0)
        ->and(ThreadSubscription::where('user_id', $other->id)->count())->toBe(1)
        ->and(ThreadSubscriptionDigestItem::where('user_id', $other->id)->count())->toBe(1);
});
