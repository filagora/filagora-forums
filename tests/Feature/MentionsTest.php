<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Events\PostCreated;
use Filagora\Forums\Events\PostEdited;
use Filagora\Forums\Events\PostModerated;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Events\UsersMentioned;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostMention;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Notifications\UserMentionedNotification;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\Mentions;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(Mentions::class);
});

function mentionUser(string $username): TestUser
{
    $user = TestUser::make($username);
    $user->forceFill(['username' => $username])->save();

    return $user->refresh();
}

function mentionPost(string $body, ?TestUser $author = null, ForumVisibility $visibility = ForumVisibility::PUBLIC, array $post = [], array $thread = []): Post
{
    $author ??= mentionUser('author'.uniqid());
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'F'.uniqid(), 'slug' => 'f-'.uniqid(), 'visibility' => $visibility]);
    $thread = Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'A thread', 'slug' => 't-'.uniqid()], $thread));

    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => $body], $post))->refresh();
}

/**
 * @return list<string>
 */
function told(Post $post): array
{
    return PostMention::where('post_id', $post->id)->orderBy('id')->get()->map(fn ($mention) => $mention->user->username)->all();
}

it('finds the names mentioned, once each, in order', function (string $body, array $names) {
    expect(app(Mentions::class)->usernames($body))->toBe($names);
})->with([
    'plain' => ['Hello @bob and @carol', ['bob', 'carol']],
    'repeated, any case' => ['@Bob @bob @BOB', ['Bob']],
    'trailing punctuation' => ['Thanks @bob. And @carol-, @dave_', ['bob', 'carol', 'dave_']],
    'inner dots and dashes' => ['@first.last @a-b', ['first.last', 'a-b']],
    'too short' => ['@a is not a name', []],
    'an email address is not a mention' => ['write to bob@example.com', []],
    'a doubled at sign is not a mention' => ['@@bob', []],
    'after punctuation' => ['(@bob) "@carol"', ['bob', 'carol']],
    'at most thirty characters' => ['@'.str_repeat('a', 40), [str_repeat('a', 30)]],
    'inline code is skipped' => ['Use `@bob` here, but @carol is fine', ['carol']],
    'fenced code is skipped' => ["```\n@bob\n```\n@carol", ['carol']],
    'a quoted post is skipped' => ["> **Alice:**\n> hi @bob\n> and @carol\nreply to @dave", ['dave']],
    'a quote ends at the first line that is not quoted' => ["> **Alice:**\n> hi @bob\n\n@carol", ['carol']],
    'a hand-typed quote is scanned' => ["> remember @bob said so\n@carol", ['bob', 'carol']],
    'no mentions' => ['Just words', []],
]);

it('records the people it tells and announces them once', function () {
    Event::fake([UsersMentioned::class]);
    $author = mentionUser('author');
    $bob = mentionUser('bob');
    $carol = mentionUser('carol');
    $post = mentionPost('Hello @Bob and @carol and @nobody', $author);

    $told = app(Mentions::class)->sync($post);

    expect($told->pluck('id')->all())->toBe([$bob->id, $carol->id])
        ->and(told($post))->toBe(['bob', 'carol'])
        ->and(PostMention::first()->notified_at)->not->toBeNull();
    Event::assertDispatched(UsersMentioned::class, fn ($event) => $event->post->is($post) && collect($event->users)->pluck('id')->all() === [$bob->id, $carol->id]);
});

it('never tells the author about their own post', function () {
    $author = mentionUser('author');
    $post = mentionPost('Talking to myself, @author', $author);

    expect(app(Mentions::class)->sync($post))->toHaveCount(0)->and(PostMention::count())->toBe(0);
});

it('tells nobody twice about the same post, however often it is edited', function () {
    Event::fake([UsersMentioned::class]);
    $bob = mentionUser('bob');
    $carol = mentionUser('carol');
    $post = mentionPost('Hello @bob', mentionUser('author'));

    app(Mentions::class)->sync($post);
    app(Mentions::class)->sync($post);
    $post->update(['body' => 'Hello @bob and @carol']);
    $told = app(Mentions::class)->sync($post);

    expect($told->pluck('id')->all())->toBe([$carol->id])->and(PostMention::count())->toBe(2)->and($bob->id)->not->toBe($carol->id);
    Event::assertDispatchedTimes(UsersMentioned::class, 2);
});

it('only tells people who can see the thread', function () {
    $outsider = mentionUser('outsider');
    $post = mentionPost('Hello @outsider', mentionUser('author'), ForumVisibility::STAFF_ONLY);

    expect(app(Mentions::class)->sync($post))->toHaveCount(0)->and(PostMention::count())->toBe(0)->and($outsider->id)->toBeInt();
});

it('tells at most the configured number of people about one post, ever', function () {
    config(['filagora-forums.mentions.max_per_post' => 3]);
    collect(range(1, 6))->each(fn ($n) => mentionUser("user{$n}"));
    $post = mentionPost('@user1 @user2 @user3 @user4 @user5', mentionUser('author'));

    expect(app(Mentions::class)->sync($post)->pluck('username')->all())->toBe(['user1', 'user2', 'user3']);

    $post->update(['body' => '@user1 @user2 @user3 @user4 @user5 @user6']);
    expect(app(Mentions::class)->sync($post))->toHaveCount(0)->and(PostMention::count())->toBe(3);

    $post->update(['body' => '@user6 @user5']);
    expect(app(Mentions::class)->sync($post))->toHaveCount(0)->and(PostMention::count())->toBe(3);
});

it('fills the remaining places in the order the names appear, skipping those who cannot be told', function () {
    config(['filagora-forums.mentions.max_per_post' => 2]);
    mentionUser('first');
    $third = mentionUser('third');
    $fourth = mentionUser('fourth');
    $post = mentionPost('@ghost @first @third @fourth', mentionUser('author'));
    PostMention::create(['post_id' => $post->id, 'user_id' => TestUser::where('username', 'first')->value('id')]);

    expect(app(Mentions::class)->sync($post)->pluck('id')->all())->toBe([$third->id])->and($fourth->id)->toBeInt();
});

it('ignores a post nobody can see yet', function (array $post, array $thread) {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'), post: $post, thread: $thread);

    expect(app(Mentions::class)->sync($post))->toHaveCount(0)->and(PostMention::count())->toBe(0);
})->with([
    'pending post' => [['moderation_status' => ContentModerationStatus::PENDING], []],
    'deleted post' => [['is_deleted' => true], []],
    'pending thread' => [[], ['moderation_status' => ContentModerationStatus::PENDING]],
]);

it('matches the configured user column, ignoring case', function () {
    config(['filagora-forums.mentions.user_attribute' => 'name']);
    $bob = TestUser::make('Bobby');
    $post = mentionPost('Hello @bobby', mentionUser('author'));

    expect(app(Mentions::class)->sync($post)->pluck('id')->all())->toBe([$bob->id]);
});

it('mentions people when a post is created, but not while it awaits approval', function () {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'));

    PostCreated::dispatch($post, $post->thread, true, false);
    expect(PostMention::count())->toBe(0);

    PostCreated::dispatch($post, $post->thread, false, false);
    expect(told($post))->toBe(['bob']);
});

it('mentions people added by an edit', function () {
    mentionUser('bob');
    $carol = mentionUser('carol');
    $post = mentionPost('Hello @bob', mentionUser('author'));
    PostCreated::dispatch($post, $post->thread, false, false);

    $post->update(['body' => 'Hello @bob and @carol']);
    PostEdited::dispatch($post, mentionUser('editor'));

    expect(told($post))->toBe(['bob', 'carol'])->and($carol->id)->toBeInt();
});

it('mentions people when a post is approved or restored later, and not on other moderation', function () {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'), post: ['moderation_status' => ContentModerationStatus::PENDING]);
    $moderator = mentionUser('mod');

    PostModerated::dispatch($post, $moderator, 'reject', null);
    PostModerated::dispatch($post, $moderator, 'delete', null);
    expect(PostMention::count())->toBe(0);

    $post->update(['moderation_status' => ContentModerationStatus::APPROVED]);
    PostModerated::dispatch($post->refresh(), $moderator, 'approve', null);
    expect(told($post))->toBe(['bob']);
});

it('mentions people in a restored post', function () {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'), post: ['is_deleted' => true]);
    $post->update(['is_deleted' => false]);

    PostModerated::dispatch($post->refresh(), mentionUser('mod'), 'restore', null);

    expect(told($post))->toBe(['bob']);
});

it('mentions the people in a thread\'s posts when the thread is approved', function () {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'), thread: ['moderation_status' => ContentModerationStatus::PENDING]);
    $moderator = mentionUser('mod');

    ThreadModerated::dispatch($post->thread, $moderator, 'lock', null);
    expect(PostMention::count())->toBe(0);

    $post->thread->update(['moderation_status' => ContentModerationStatus::APPROVED]);
    ThreadModerated::dispatch($post->thread->refresh(), $moderator, 'approve', null);
    expect(told($post))->toBe(['bob']);
});

it('does nothing automatically when mentions are switched off', function () {
    config(['filagora-forums.mentions.enabled' => false]);
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'));

    PostCreated::dispatch($post, $post->thread, false, false);
    PostEdited::dispatch($post, mentionUser('editor'));
    PostModerated::dispatch($post, mentionUser('mod'), 'approve', null);
    ThreadModerated::dispatch($post->thread, mentionUser('mod2'), 'approve', null);

    expect(PostMention::count())->toBe(0)->and(app(Mentions::class)->sync($post))->toHaveCount(1);
});

it('emails the people mentioned, linking to the post when the host names a route', function () {
    Notification::fake();
    Route::get('/t/{thread}', fn () => '')->name('threads.show');
    app('router')->getRoutes()->refreshNameLookups();
    config(['filagora-forums.mentions.routes.thread' => 'threads.show']);
    $bob = mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'));

    app(Mentions::class)->sync($post);

    Notification::assertSentTo($bob, UserMentionedNotification::class);
    $mail = (new UserMentionedNotification($post))->toMail($bob);
    expect($mail->subject)->toBe('You were mentioned in A thread')
        ->and($mail->actionUrl)->toBe(route('threads.show', $post->thread).'#post-'.$post->id);

    config(['filagora-forums.mentions.routes.thread' => null]);
    expect((new UserMentionedNotification($post))->toMail($bob)->actionUrl)->toBeNull();
});

it('sends no email when the host turns it off, but still announces the mention', function () {
    Notification::fake();
    config(['filagora-forums.mentions.mail.enabled' => false]);
    $bob = mentionUser('bob');
    $heard = [];
    Event::listen(UsersMentioned::class, function (UsersMentioned $event) use (&$heard) {
        $heard = collect($event->users)->pluck('id')->all();
    });

    app(Mentions::class)->sync(mentionPost('Hello @bob', mentionUser('author')));

    Notification::assertNothingSent();
    expect($heard)->toBe([$bob->id]);
});

it('removes mentions with the post or the person', function () {
    $bob = mentionUser('bob');
    $carol = mentionUser('carol');
    $post = mentionPost('@bob @carol', mentionUser('author'));
    app(Mentions::class)->sync($post);

    $bob->delete();
    expect(PostMention::count())->toBe(1);

    $post->delete();
    expect(PostMention::count())->toBe(0)->and($carol->exists)->toBeTrue();
});

it('exports and erases a person\'s mentions, keeping everyone else\'s', function () {
    $bob = mentionUser('bob');
    $carol = mentionUser('carol');
    $post = mentionPost('@bob @carol', mentionUser('author'));
    app(Mentions::class)->sync($post);
    $contributor = app(PrivacyContributorRegistry::class)->all()['forums'];

    $export = $contributor->export($bob);
    expect($export['mentions'])->toHaveCount(1)->and($export['mentions'][0]['post_id'])->toBe($post->id);

    $contributor->erase($bob);
    expect(PostMention::pluck('user_id')->all())->toBe([$carol->id]);
});

it('allows one mention of a person per post at the database level', function () {
    $bob = mentionUser('bob');
    $post = mentionPost('hi', mentionUser('author'));
    PostMention::create(['post_id' => $post->id, 'user_id' => $bob->id]);

    expect(fn () => PostMention::create(['post_id' => $post->id, 'user_id' => $bob->id]))->toThrow(QueryException::class);
});

it('ignores a name that is only one letter once the trailing punctuation is gone', function () {
    expect(app(Mentions::class)->usernames('Hello @a. and @bb.'))->toBe(['bb']);
});

it('has nothing to do for a post that mentions nobody', function () {
    Event::fake([UsersMentioned::class]);
    $post = mentionPost('Just words, no names', mentionUser('author'));

    expect(app(Mentions::class)->sync($post))->toHaveCount(0)->and(PostMention::count())->toBe(0);
    Event::assertNothingDispatched();
});

it('does not tell someone who can see the forum but not this thread', function () {
    $author = mentionUser('author');
    $bob = mentionUser('bob');
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'Private', 'slug' => 'private', 'visibility' => ForumVisibility::STAFF_AUTHOR_ONLY])->refresh();
    Thread::create(['forum_id' => $forum->id, 'author_id' => $bob->id, 'title' => 'Bob\'s own', 'slug' => 'bobs']);
    $theirs = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'Theirs', 'slug' => 'theirs']);
    $post = Post::create(['thread_id' => $theirs->id, 'author_id' => $author->id, 'body' => 'Hello @bob'])->refresh();

    expect(app(ForumAccess::class)->canViewForum($forum, $bob))->toBeTrue()
        ->and(app(Mentions::class)->sync($post))->toHaveCount(0)
        ->and(PostMention::count())->toBe(0);
});

it('leaves other moderation of a post alone', function () {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'));

    foreach (['move', 'promote', 'unapprove'] as $action) {
        PostModerated::dispatch($post, mentionUser("mod-{$action}"), $action, null);
    }

    expect(PostMention::count())->toBe(0);
});

it('leaves other moderation of a thread alone', function () {
    mentionUser('bob');
    $post = mentionPost('Hello @bob', mentionUser('author'));

    foreach (['lock', 'pin', 'move', 'rename'] as $action) {
        ThreadModerated::dispatch($post->thread, mentionUser("mod-{$action}"), $action, null);
    }

    expect(PostMention::count())->toBe(0);
});
