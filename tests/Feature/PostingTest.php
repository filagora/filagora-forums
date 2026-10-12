<?php

declare(strict_types=1);

use ForumKit\Core\Models\Setting;
use ForumKit\Core\Settings\SettingsRegistry;
use ForumKit\Core\Settings\SettingType;
use ForumKit\Entitlements\Models\UserEntitlementGrant;
use ForumKit\Forums\Contracts\ContentModerationPolicy;
use ForumKit\Forums\Contracts\PostSignatureResolver;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Enums\ForumPostingPolicy;
use ForumKit\Forums\Enums\ForumVisibility;
use ForumKit\Forums\Enums\PostingDenial;
use ForumKit\Forums\Events\PostCreated;
use ForumKit\Forums\Events\PostEdited;
use ForumKit\Forums\Events\ThreadCreated;
use ForumKit\Forums\Exceptions\PostingNotPermitted;
use ForumKit\Forums\Models\Forum;
use ForumKit\Forums\Models\ForumGroup;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\Thread;
use ForumKit\Forums\Services\ApprovalRules;
use ForumKit\Forums\Services\ForumAccess;
use ForumKit\Forums\Services\Posting;
use ForumKit\Forums\Services\ReplyCreation;
use ForumKit\Forums\Services\ThreadCreation;
use ForumKit\Forums\Support\Abilities;
use ForumKit\Forums\Support\NoContentModeration;
use ForumKit\Forums\Support\NoPostSignature;
use ForumKit\Forums\Tests\Support\TestUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->moderatedIds = [];
    $this->awaitingIds = [];
    $this->signatures = [];

    app()->instance(ContentModerationPolicy::class, new class($this) implements ContentModerationPolicy
    {
        public function __construct(private object $test) {}

        public function requiresModeration(Authenticatable $user): bool
        {
            return in_array($user->getAuthIdentifier(), $this->test->moderatedIds, true);
        }

        public function awaitingFirstReview(Authenticatable $user): bool
        {
            return in_array($user->getAuthIdentifier(), $this->test->awaitingIds, true);
        }
    });

    app()->instance(PostSignatureResolver::class, new class($this) implements PostSignatureResolver
    {
        public function __construct(private object $test) {}

        public function signatureFor(Authenticatable $user): ?string
        {
            return $this->test->signatures[$user->getAuthIdentifier()] ?? null;
        }
    });

    foreach ([ApprovalRules::class, ThreadCreation::class, ReplyCreation::class, Posting::class, ForumAccess::class] as $service) {
        app()->forgetInstance($service);
    }
});

function pForum(array $attributes = []): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(array_merge(['forum_group_id' => $group->id, 'name' => 'Forum '.uniqid(), 'slug' => 'forum-'.uniqid()], $attributes))->refresh();
}

function pEntitled(TestUser $user, string $key): TestUser
{
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);

    return $user;
}

function pThreadBy(Forum $forum, TestUser $author, array $attributes = []): Thread
{
    return Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't-'.uniqid()], $attributes))->refresh();
}

function pForumRow(Forum $forum): object
{
    return DB::table((new Forum)->getTable())->where('id', $forum->id)->first();
}

function pDenial(callable $call): ?PostingDenial
{
    try {
        $call();
    } catch (PostingNotPermitted $e) {
        return $e->reason;
    }

    return null;
}

// ------------------------------------------------------------------ approval rules

dataset('approval rules', [
    //                                  forum asks, moderated user, can bypass, => thread/reply needs approval
    'forum does not ask' => [false, false, false, false],
    'forum asks' => [true, false, false, true],
    'forum asks, user bypasses' => [true, false, true, false],
    'moderated user, forum does not ask' => [false, true, false, true],
    'moderated user beats the bypass' => [true, true, true, true],
]);

it('decides whether a new thread or reply waits for a moderator', function (bool $asks, bool $moderated, bool $bypasses, bool $expected) {
    $forum = pForum(['requires_thread_approval' => $asks, 'requires_reply_approval' => $asks]);
    $user = TestUser::make();

    if ($moderated) {
        $this->moderatedIds = [$user->id];
    }

    if ($bypasses) {
        pEntitled($user, Abilities::BYPASS_APPROVAL);
    }

    $rules = app(ApprovalRules::class);

    expect($rules->threadRequiresApproval($forum, $user))->toBe($expected)
        ->and($rules->replyRequiresApproval($forum, $user))->toBe($expected);
})->with('approval rules');

it('applies the forum\'s approval flags separately to threads and replies, and to guests', function () {
    $rules = app(ApprovalRules::class);
    $forum = pForum(['requires_thread_approval' => true, 'requires_reply_approval' => false]);

    expect($rules->threadRequiresApproval($forum, null))->toBeTrue()
        ->and($rules->replyRequiresApproval($forum, null))->toBeFalse()
        ->and($rules->threadRequiresApproval($forum))->toBeTrue();
});

// -------------------------------------------------------------------- new threads

it('creates a thread and its first post, and counts them in the forum', function () {
    Event::fake([ThreadCreated::class, PostCreated::class]);
    $forum = pForum();
    $author = TestUser::make('Author');

    $created = app(Posting::class)->startThread($author, $forum, '  My question  ', "  How do I? \n");

    $thread = $created->thread;
    $post = $created->post;

    expect($created->requiresApproval)->toBeFalse()->and($created->duplicate)->toBeFalse()
        ->and($thread->title)->toBe('My question')
        ->and($thread->slug)->toBe('my-question')
        ->and($thread->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($thread->approved_by_id)->toBe($author->id)
        ->and($thread->approved_at)->not->toBeNull()
        ->and($thread->is_locked)->toBeFalse()->and($thread->is_pinned)->toBeFalse()->and($thread->reply_count)->toBe(0)
        ->and($thread->last_post_author_id)->toBe($author->id)
        ->and($thread->last_post_at->toDateTimeString())->toBe($post->created_at->toDateTimeString())
        ->and($post->body)->toBe('How do I?')
        ->and($post->content_format)->toBe('markdown')
        ->and($post->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($post->signature)->toBeNull()->and($post->signature_format)->toBeNull()
        ->and((int) pForumRow($forum)->thread_count)->toBe(1)
        ->and((int) pForumRow($forum)->post_count)->toBe(1)
        ->and((int) pForumRow($forum)->last_post_id)->toBe($post->id)
        ->and((int) pForumRow($forum)->last_post_thread_id)->toBe($thread->id);

    Event::assertDispatched(ThreadCreated::class, fn (ThreadCreated $e) => $e->thread->is($thread) && $e->post->is($post) && ! $e->requiresApproval && $e->subscribeAuthor);
    Event::assertDispatched(PostCreated::class, fn (PostCreated $e) => $e->post->is($post) && $e->isFirstPost && ! $e->requiresApproval);
});

it('holds a thread for approval when the forum asks, and counts nothing until it is approved', function () {
    $forum = pForum(['requires_thread_approval' => true]);
    $author = TestUser::make();

    $created = app(Posting::class)->startThread($author, $forum, 'Hold me', 'Body');

    expect($created->requiresApproval)->toBeTrue()
        ->and($created->thread->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and($created->post->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and($created->thread->approved_by_id)->toBeNull()->and($created->thread->approved_at)->toBeNull()
        ->and($created->thread->last_post_at)->toBeNull()
        ->and((int) pForumRow($forum)->thread_count)->toBe(0)
        ->and((int) pForumRow($forum)->post_count)->toBe(0);

    // Approving the thread counts it and its approved post (the forum's counters are rebuilt).
    $created->thread->update(['moderation_status' => ContentModerationStatus::APPROVED]);
    $created->post->update(['moderation_status' => ContentModerationStatus::APPROVED]);

    expect((int) pForumRow($forum)->thread_count)->toBe(1)->and((int) pForumRow($forum)->post_count)->toBe(1);
});

it('holds a thread for a user whom the host always moderates', function () {
    $forum = pForum();
    $author = TestUser::make();
    $this->moderatedIds = [$author->id];

    expect(app(Posting::class)->startThread($author, $forum, 'Moderated', 'Body')->requiresApproval)->toBeTrue();
});

it('copies the author\'s signature onto the post, or none', function () {
    $forum = pForum();
    $signed = TestUser::make('Signed');
    $this->signatures = [$signed->id => '-- Signed'];

    $with = app(Posting::class)->startThread($signed, $forum, 'One', 'Body');
    $without = app(Posting::class)->startThread(TestUser::make('Plain'), $forum, 'Two', 'Body');

    expect($with->post->signature)->toBe('-- Signed')->and($with->post->signature_format)->toBe('markdown')
        ->and($without->post->signature)->toBeNull()->and($without->post->signature_format)->toBeNull();
});

it('passes on whether the author wants to follow the thread', function () {
    Event::fake([ThreadCreated::class]);

    app(Posting::class)->startThread(TestUser::make(), pForum(), 'Quiet', 'Body', subscribeAuthor: false);

    Event::assertDispatched(ThreadCreated::class, fn (ThreadCreated $e) => $e->subscribeAuthor === false);
});

it('lets staff, and only staff, hold a thread for approval or lock it from the start', function () {
    $forum = pForum();
    $staff = pEntitled(TestUser::make('Staff'), Abilities::MODERATE);
    $member = TestUser::make('Member');
    $posting = app(Posting::class);

    $byStaff = $posting->startThread($staff, $forum, 'Staff thread', 'Body', postAsUnapproved: true, lockThread: true);
    $byMember = $posting->startThread($member, $forum, 'Member thread', 'Body', postAsUnapproved: true, lockThread: true);

    expect($byStaff->requiresApproval)->toBeTrue()->and($byStaff->thread->is_locked)->toBeTrue()
        ->and($byMember->requiresApproval)->toBeFalse()->and($byMember->thread->is_locked)->toBeFalse();
});

it('gives each thread a unique slug', function () {
    $forum = pForum();
    $author = TestUser::make();
    $posting = app(Posting::class);
    $slugs = [];

    foreach (['Hello World', 'Hello World ', 'hello  world!'] as $i => $title) {
        // Different bodies, so they are not taken for a double submission.
        $slugs[] = $posting->startThread($author, $forum, $title, "Body {$i}")->thread->slug;
    }

    foreach (['???', '!!!'] as $i => $title) {
        $slugs[] = $posting->startThread($author, $forum, $title, "Other {$i}")->thread->slug;
    }

    // The longest title the column holds; its slug is cut so the numeric suffix still fits.
    $long = $posting->startThread($author, $forum, trim(str_repeat('word ', 51)), 'Long')->thread->slug;

    expect($slugs)->toBe(['hello-world', 'hello-world-2', 'hello-world-3', 'thread', 'thread-2'])
        ->and(strlen($long))->toBeLessThanOrEqual(240)
        ->and(app(ThreadCreation::class)->uniqueSlug('Hello World'))->toBe('hello-world-4');
});

it('does not count a thread\'s own slug against it when renaming', function () {
    $forum = pForum();
    $thread = app(Posting::class)->startThread(TestUser::make(), $forum, 'Keep me', 'Body')->thread;

    expect(app(ThreadCreation::class)->uniqueSlug('Keep me', $thread->id))->toBe('keep-me')
        ->and(app(ThreadCreation::class)->uniqueSlug('Keep me'))->toBe('keep-me-2');
});

// ------------------------------------------------------- double submissions (threads)

it('takes an identical thread started moments ago for a double submission', function () {
    Event::fake([ThreadCreated::class]);
    $forum = pForum();
    $author = TestUser::make();
    $posting = app(Posting::class);

    $first = $posting->startThread($author, $forum, 'Same title', 'Same body');
    $again = $posting->startThread($author, $forum, ' Same title ', "Same body\n");

    expect($again->duplicate)->toBeTrue()->and($again->thread->is($first->thread))->toBeTrue()->and($again->post->is($first->post))->toBeTrue()
        ->and(Thread::count())->toBe(1)
        ->and(Post::count())->toBe(1);

    Event::assertDispatchedTimes(ThreadCreated::class, 1);

    $this->travel(ThreadCreation::DUPLICATE_SUBMISSION_WINDOW_SECONDS + 1)->seconds();

    expect($posting->startThread($author, $forum, 'Same title', 'Same body')->duplicate)->toBeFalse()
        ->and(Thread::count())->toBe(2);
});

it('does not take a different thread for a double submission', function () {
    $forum = pForum();
    $author = TestUser::make();
    $posting = app(Posting::class);
    $posting->startThread($author, $forum, 'Title', 'Body');

    foreach ([
        [$author, $forum, 'Title', 'Another body'],
        [$author, $forum, 'Another title', 'Body'],
        [TestUser::make('Other'), $forum, 'Title', 'Body'],
        [$author, pForum(), 'Title', 'Body'],
    ] as $case) {
        expect($posting->startThread(...$case)->duplicate)->toBeFalse();
    }

    expect(Thread::count())->toBe(5);
});

// ------------------------------------------------------------------------- replies

it('creates an approved reply and updates the thread and forum counters', function () {
    $forum = pForum();
    $author = TestUser::make('Author');
    $replier = TestUser::make('Replier');
    $thread = app(Posting::class)->startThread($author, $forum, 'Question', 'Body')->thread;

    Event::fake([PostCreated::class]);

    $reply = app(Posting::class)->reply($replier, $thread->fresh(), '  An answer  ');
    $post = $reply->post;

    expect($reply->requiresApproval)->toBeFalse()->and($reply->duplicate)->toBeFalse()
        ->and($post->body)->toBe('An answer')
        ->and($post->content_format)->toBe('markdown')
        ->and($post->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($post->approved_by_id)->toBe($replier->id)
        ->and($thread->refresh()->reply_count)->toBe(1)
        ->and($thread->last_post_author_id)->toBe($replier->id)
        ->and($thread->last_post_at->toDateTimeString())->toBe($post->created_at->toDateTimeString())
        ->and((int) pForumRow($forum)->post_count)->toBe(2)
        ->and((int) pForumRow($forum)->thread_count)->toBe(1)
        ->and((int) pForumRow($forum)->last_post_id)->toBe($post->id);

    Event::assertDispatched(PostCreated::class, fn (PostCreated $e) => $e->post->is($post) && ! $e->isFirstPost && ! $e->requiresApproval);
});

it('holds a reply for approval when the forum asks, leaving the counters alone', function () {
    $forum = pForum(['requires_reply_approval' => true]);
    $thread = app(Posting::class)->startThread(TestUser::make('Author'), $forum, 'Question', 'Body')->thread;
    $before = pForumRow($forum);

    $reply = app(Posting::class)->reply(TestUser::make('Replier'), $thread, 'Waiting');

    expect($reply->requiresApproval)->toBeTrue()
        ->and($reply->post->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and($reply->post->approved_by_id)->toBeNull()
        ->and($thread->refresh()->reply_count)->toBe(0)
        ->and((int) pForumRow($forum)->post_count)->toBe((int) $before->post_count);

    $reply->post->update(['moderation_status' => ContentModerationStatus::APPROVED]);

    expect((int) pForumRow($forum)->post_count)->toBe((int) $before->post_count + 1);
});

it('lets someone who can bypass approval reply straight away in a forum that asks for it', function () {
    $forum = pForum(['requires_reply_approval' => true]);
    $thread = app(Posting::class)->startThread(pEntitled(TestUser::make('Staff'), Abilities::BYPASS_APPROVAL), $forum, 'Q', 'Body')->thread;

    expect(app(Posting::class)->reply(pEntitled(TestUser::make('Trusted'), Abilities::BYPASS_APPROVAL), $thread, 'Straight in')->requiresApproval)->toBeFalse();
});

it('counts a thread\'s replies from its approved, undeleted posts only', function () {
    $forum = pForum();
    $author = TestUser::make('Author');
    $thread = app(Posting::class)->startThread($author, $forum, 'Q', 'Body')->thread;
    Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'gone', 'is_deleted' => true]);
    Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'pending', 'moderation_status' => ContentModerationStatus::PENDING]);

    app(Posting::class)->reply(TestUser::make('Replier'), $thread, 'Counted');

    // The opening post and the new reply are the only approved, undeleted ones.
    expect($thread->refresh()->reply_count)->toBe(1);
});

it('takes an identical reply posted moments ago for a double submission', function () {
    Event::fake([PostCreated::class]);
    $forum = pForum();
    $thread = app(Posting::class)->startThread(TestUser::make('Author'), $forum, 'Q', 'Body')->thread;
    $replier = TestUser::make('Replier');
    $posting = app(Posting::class);

    $first = $posting->reply($replier, $thread, 'Same answer');
    $again = $posting->reply($replier, $thread, " Same answer\n");

    expect($again->duplicate)->toBeTrue()->and($again->post->is($first->post))->toBeTrue()->and(Post::count())->toBe(2);

    // The opening post's event and the one reply's; the double submission fired none.
    Event::assertDispatchedTimes(PostCreated::class, 2);

    $this->travel(ThreadCreation::DUPLICATE_SUBMISSION_WINDOW_SECONDS + 1)->seconds();

    expect($posting->reply($replier, $thread, 'Same answer')->duplicate)->toBeFalse()->and(Post::count())->toBe(3);
});

it('copies the replier\'s signature onto the reply', function () {
    $forum = pForum();
    $thread = app(Posting::class)->startThread(TestUser::make('Author'), $forum, 'Q', 'Body')->thread;
    $replier = TestUser::make('Replier');
    $this->signatures = [$replier->id => '-- Replier'];

    $post = app(Posting::class)->reply($replier, $thread, 'Answer')->post;

    expect($post->signature)->toBe('-- Replier')->and($post->signature_format)->toBe('markdown');
});

// ------------------------------------------------------------------ refusals

it('refuses to start a thread where the user may not, and says why', function () {
    $author = TestUser::make();
    $posting = fn (Forum $forum) => fn () => app(Posting::class)->startThread($author, $forum, 'Title '.uniqid(), 'Body');

    expect(pDenial($posting(pForum(['visibility' => ForumVisibility::STAFF_ONLY]))))->toBe(PostingDenial::FORUM_NOT_VISIBLE)
        ->and(pDenial($posting(pForum(['is_active' => false]))))->toBe(PostingDenial::FORUM_NOT_VISIBLE)
        ->and(pDenial($posting(pForum(['is_read_only' => true]))))->toBe(PostingDenial::NOT_ALLOWED)
        ->and(pDenial($posting(pForum(['posting_policy' => ForumPostingPolicy::STAFF_ONLY]))))->toBe(PostingDenial::NOT_ALLOWED)
        ->and(pDenial($posting(pForum())))->toBeNull();

    $this->awaitingIds = [$author->id];

    expect(pDenial($posting(pForum())))->toBe(PostingDenial::AWAITING_FIRST_REVIEW)
        ->and(Thread::count())->toBe(1);
});

it('refuses a reply where the user may not, and says why', function () {
    $author = TestUser::make('Author');
    $replier = TestUser::make('Replier');
    $reply = fn (Thread $thread) => fn () => app(Posting::class)->reply($replier, $thread, 'Answer '.uniqid());

    $open = app(Posting::class)->startThread($author, pForum(), 'Open', 'Body')->thread;
    $hidden = pThreadBy(pForum(['visibility' => ForumVisibility::STAFF_ONLY]), $author);
    $pending = pThreadBy(pForum(), $author, ['moderation_status' => ContentModerationStatus::PENDING]);
    $locked = pThreadBy(pForum(), $author, ['is_locked' => true]);
    $club = app(Posting::class)->startThread($author, pForum(['reply_entitlement_scope' => 'club.access']), 'Club', 'Body')->thread;

    expect(pDenial($reply($open)))->toBeNull()
        ->and(pDenial($reply($hidden)))->toBe(PostingDenial::FORUM_NOT_VISIBLE)
        ->and(pDenial($reply($pending)))->toBe(PostingDenial::THREAD_NOT_ACCESSIBLE)
        ->and(pDenial($reply($locked)))->toBe(PostingDenial::THREAD_LOCKED)
        ->and(pDenial($reply($club)))->toBe(PostingDenial::NOT_ALLOWED);

    // A thread's own author reaches their pending thread but cannot reply until it is approved.
    expect(pDenial(fn () => app(Posting::class)->reply($author, $pending, 'Mine')))->toBe(PostingDenial::THREAD_NOT_APPROVED);

    $this->awaitingIds = [$replier->id];
    expect(pDenial($reply($open)))->toBe(PostingDenial::AWAITING_FIRST_REVIEW);
});

it('carries the reason and a readable message on the exception', function () {
    try {
        app(Posting::class)->startThread(TestUser::make(), pForum(['is_read_only' => true]), 'T', 'B');
    } catch (PostingNotPermitted $e) {
        expect($e->reason)->toBe(PostingDenial::NOT_ALLOWED)->and($e->getMessage())->toBe('You are not allowed to do that here.');

        return;
    }

    $this->fail('Expected a refusal.');
});

it('gives every refusal a message', function () {
    foreach (PostingDenial::cases() as $case) {
        expect($case->message())->not->toBe('');
    }
});

// -------------------------------------------------------------------------- editing

function pPost(?TestUser $author = null, array $forumAttributes = []): Post
{
    $author ??= TestUser::make('Author');
    $thread = app(Posting::class)->startThread($author, pForum($forumAttributes), 'Q '.uniqid(), 'Original')->thread;

    return $thread->posts()->first()->load('thread.forum');
}

it('lets an author edit their own post within the edit window, and marks the edit', function () {
    Event::fake([PostEdited::class]);
    $author = TestUser::make();
    $post = pPost($author);

    expect(app(Posting::class)->editPost($author, $post, '  Better wording  '))->toBeTrue();

    $post->refresh();

    expect($post->body)->toBe('Better wording')->and($post->content_format)->toBe('markdown')
        ->and($post->edited_by_id)->toBe($author->id)->and($post->edited_at)->not->toBeNull();

    Event::assertDispatched(PostEdited::class, fn (PostEdited $e) => $e->post->is($post) && $e->editor->is($author));
});

it('says nothing changed when the body is the same, and records no edit', function () {
    Event::fake([PostEdited::class]);
    $author = TestUser::make();
    $post = pPost($author);

    expect(app(Posting::class)->editPost($author, $post, " Original\n"))->toBeFalse();

    Event::assertNotDispatched(PostEdited::class);
    expect($post->refresh()->edited_at)->toBeNull()->and($post->edited_by_id)->toBeNull();
});

it('stops an author editing once the window has passed, but not staff', function () {
    $author = TestUser::make();
    $staff = pEntitled(TestUser::make('Staff'), Abilities::MODERATE);
    $post = pPost($author);
    $posting = app(Posting::class);

    $this->travel(Post::authorEditWindowInMinutes() + 1)->minutes();

    expect(pDenial(fn () => $posting->editPost($author, $post, 'Too late')))->toBe(PostingDenial::NOT_ALLOWED)
        ->and($posting->editPost($staff, $post, 'Moderator fix'))->toBeTrue()
        ->and($post->refresh()->edited_by_id)->toBe($staff->id);
});

it('follows the edit window setting, and a window of zero turns author editing off', function () {
    $author = TestUser::make();
    $post = pPost($author);
    $posting = app(Posting::class);

    expect(Post::authorEditWindowInMinutes())->toBe(30);

    Setting::create(['key' => 'forum_post_edit_window_minutes', 'value_type' => 'integer', 'value' => '5']);
    expect(Post::authorEditWindowInMinutes())->toBe(5);

    $this->travel(6)->minutes();
    expect(pDenial(fn () => $posting->editPost($author, $post, 'x')))->toBe(PostingDenial::NOT_ALLOWED);

    // Settings change through the model, which tells the registry to drop what it remembered.
    $this->travelBack();
    Setting::where('key', 'forum_post_edit_window_minutes')->first()->update(['value' => '0']);
    expect(Post::authorEditWindowInMinutes())->toBe(0)
        ->and(pDenial(fn () => $posting->editPost($author, $post, 'x')))->toBe(PostingDenial::NOT_ALLOWED);

    Setting::where('key', 'forum_post_edit_window_minutes')->first()->update(['value' => '-10']);
    expect(Post::authorEditWindowInMinutes())->toBe(0);
});

it('lets no one but staff edit someone else\'s post, or a deleted one', function () {
    $author = TestUser::make();
    $post = pPost($author);
    $posting = app(Posting::class);

    expect(pDenial(fn () => $posting->editPost(TestUser::make('Other'), $post, 'Mine now')))->toBe(PostingDenial::NOT_ALLOWED);

    $post->update(['is_deleted' => true]);

    expect(pDenial(fn () => $posting->editPost($author, $post->refresh(), 'Undelete')))->toBe(PostingDenial::NOT_ALLOWED)
        ->and(app(ForumAccess::class)->canEditPost($post, null))->toBeFalse();
});

it('does not let anyone edit a post in a thread they can no longer see', function () {
    $author = TestUser::make();
    $post = pPost($author);
    $post->thread->forum->update(['visibility' => ForumVisibility::STAFF_ONLY]);

    expect(app(ForumAccess::class)->canEditPost($post->refresh()->load('thread.forum'), $author))->toBeFalse();
});

it('marks a post edited only when the edit came after the grace period', function () {
    $author = TestUser::make();
    $post = pPost($author);

    expect($post->shouldShowEditedMarker())->toBeFalse()->and(Post::editedMarkerGracePeriodInMinutes())->toBe(5);

    $post->update(['edited_at' => $post->created_at->copy()->addMinutes(3)]);
    expect($post->refresh()->shouldShowEditedMarker())->toBeFalse();

    $post->update(['edited_at' => $post->created_at->copy()->addMinutes(6)]);
    expect($post->refresh()->shouldShowEditedMarker())->toBeTrue();

    Setting::create(['key' => 'forum_post_edit_grace_period_minutes', 'value_type' => 'integer', 'value' => '10']);
    expect($post->refresh()->shouldShowEditedMarker())->toBeFalse();
});

// ------------------------------------------------------- settings and defaults

it('declares the edit settings with the live forum\'s keys, types and defaults', function () {
    $settings = app(SettingsRegistry::class);

    foreach (['forum_post_edit_window_minutes' => 30, 'forum_post_edit_grace_period_minutes' => 5] as $key => $default) {
        $definition = $settings->definition($key);

        expect($definition)->not->toBeNull()
            ->and($definition->type)->toBe(SettingType::Integer)
            ->and($definition->default)->toBe($default)
            ->and($definition->label)->not->toBeNull();
    }
});

it('keeps those defaults when a worker resets the request scope between jobs', function () {
    app()->forgetScopedInstances();

    expect(Post::authorEditWindowInMinutes())->toBe(30)->and(Post::editedMarkerGracePeriodInMinutes())->toBe(5);
});

it('imposes nothing and signs nothing until the host binds its own rules', function () {
    app()->forgetInstance(ContentModerationPolicy::class);
    app()->forgetInstance(PostSignatureResolver::class);
    app()->bind(ContentModerationPolicy::class, NoContentModeration::class);
    app()->bind(PostSignatureResolver::class, NoPostSignature::class);
    $user = TestUser::make();

    expect(app(ContentModerationPolicy::class)->requiresModeration($user))->toBeFalse()
        ->and(app(ContentModerationPolicy::class)->awaitingFirstReview($user))->toBeFalse()
        ->and(app(PostSignatureResolver::class)->signatureFor($user))->toBeNull();
});
