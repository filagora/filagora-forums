<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Filament\Pages\ModerationQueue;
use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;
use Filagora\Forums\Filament\Resources\ForumGroups\Pages\CreateForumGroup;
use Filagora\Forums\Filament\Resources\ForumGroups\Pages\EditForumGroup;
use Filagora\Forums\Filament\Resources\Forums\ForumResource;
use Filagora\Forums\Filament\Resources\Forums\Pages\CreateForum;
use Filagora\Forums\Filament\Resources\Forums\Pages\ListForums;
use Filagora\Forums\Filament\Resources\PostReports\Pages\ListPostReports;
use Filagora\Forums\Filament\Resources\PostReports\PostReportResource;
use Filagora\Forums\Filament\Resources\Posts\Pages\ListPosts;
use Filagora\Forums\Filament\Resources\Posts\PostResource;
use Filagora\Forums\Filament\Resources\Threads\Pages\ListThreads;
use Filagora\Forums\Filament\Resources\Threads\ThreadResource;
use Filagora\Forums\Filament\Widgets\ModerationQueueStats;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    app()->forgetInstance(ForumAccess::class);

    $this->author = TestUser::make('Author');
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $this->forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'General', 'slug' => 'general'])->refresh();
});

function fModerator(string ...$keys): TestUser
{
    $user = TestUser::make('Mod');

    foreach ($keys ?: [Abilities::MODERATE] as $key) {
        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);
    }

    actingAs($user);

    return $user;
}

function fThread(Forum $forum, TestUser $author, array $attributes = []): Thread
{
    $thread = Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'Hello '.uniqid(), 'slug' => 't-'.uniqid()], $attributes))->refresh();
    fPost($thread, $author, ['moderation_status' => $thread->moderation_status->value]);

    return $thread->refresh();
}

function fPost(Thread $thread, TestUser $author, array $attributes = []): Post
{
    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'body '.uniqid(), 'content_format' => 'markdown'], $attributes))->refresh();
}

// ------------------------------------------------------------------ access

it('shows the screens to moderators only', function () {
    $resources = [ForumGroupResource::class, ForumResource::class, ThreadResource::class, PostResource::class, PostReportResource::class];

    fModerator(Abilities::VIEW_UNAPPROVED);
    foreach ($resources as $resource) {
        expect($resource::canViewAny())->toBeFalse($resource);
    }
    expect(ModerationQueue::canAccess())->toBeFalse()
        ->and(ModerationQueueStats::canView())->toBeFalse();

    fModerator();
    foreach ($resources as $resource) {
        expect($resource::canViewAny())->toBeTrue($resource);
    }
    expect(ModerationQueue::canAccess())->toBeTrue()
        ->and(ModerationQueueStats::canView())->toBeTrue()
        ->and(ThreadResource::canCreate())->toBeFalse()
        ->and(PostResource::canCreate())->toBeFalse()
        ->and(PostReportResource::canCreate())->toBeFalse()
        ->and(ForumResource::canCreate())->toBeTrue();
});

it('registers the resources, queue page and widget with the panel', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getResources())->toContain(ForumResource::class, ForumGroupResource::class, ThreadResource::class, PostResource::class, PostReportResource::class)
        ->and($panel->getPages())->toContain(ModerationQueue::class)
        ->and($panel->getWidgets())->toContain(ModerationQueueStats::class);
});

// ------------------------------------------------------------------ groups and forums

it('creates and edits forum groups and forums', function () {
    fModerator();

    Livewire::test(CreateForumGroup::class)
        ->fillForm(['name' => 'Support', 'slug' => 'support', 'sort_order' => 2, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $group = ForumGroup::query()->where('slug', 'support')->firstOrFail();

    Livewire::test(EditForumGroup::class, ['record' => $group->getKey()])
        ->fillForm(['name' => 'Help'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($group->refresh()->name)->toBe('Help');

    Livewire::test(CreateForum::class)
        ->fillForm(['forum_group_id' => $group->id, 'name' => 'Installing', 'slug' => 'installing', 'visibility' => 'registered', 'posting_policy' => 'registered', 'sort_order' => 1, 'requires_reply_approval' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $forum = Forum::query()->where('slug', 'installing')->firstOrFail();
    expect($forum->visibility->value)->toBe('registered')
        ->and($forum->requires_reply_approval)->toBeTrue();

    Livewire::test(ListForums::class)->assertCanSeeTableRecords([$forum, $this->forum]);
});

it('requires a name, slug and group for a forum', function () {
    fModerator();

    Livewire::test(CreateForum::class)
        ->fillForm(['name' => null, 'slug' => null, 'forum_group_id' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'slug' => 'required', 'forum_group_id' => 'required']);
});

// ------------------------------------------------------------------ threads

it('locks, pins, renames, moves and approves a thread from the table, with a log row each', function () {
    $mod = fModerator();
    $thread = fThread($this->forum, $this->author);
    $other = Forum::create(['forum_group_id' => $this->forum->forum_group_id, 'name' => 'Other', 'slug' => 'other'])->refresh();

    $test = Livewire::test(ListThreads::class)->assertCanSeeTableRecords([$thread]);

    $test->callTableAction('lockThread', $thread, ['reason' => 'heated'])->assertNotified();
    $test->callTableAction('pinThread', $thread);
    $test->callTableAction('renameThread', $thread, ['title' => 'Renamed']);
    $test->callTableAction('moveThread', $thread, ['forum_id' => $other->id, 'reason' => null]);

    $thread->refresh();
    expect($thread->is_locked)->toBeTrue()
        ->and($thread->is_pinned)->toBeTrue()
        ->and($thread->title)->toBe('Renamed')
        ->and($thread->forum_id)->toBe($other->id)
        ->and(ThreadModerationLog::orderBy('id')->pluck('action')->all())->toBe(['lock', 'pin', 'rename', 'move'])
        ->and(ThreadModerationLog::first()->moderator_id)->toBe($mod->id)
        ->and(ThreadModerationLog::first()->reason)->toBe('heated');

    $test->assertTableActionHidden('lockThread', $thread)->assertTableActionVisible('unlockThread', $thread);
});

it('offers approval only for threads that are not approved, and approves them', function () {
    fModerator();
    $approved = fThread($this->forum, $this->author);
    $pending = fThread($this->forum, $this->author, ['moderation_status' => 'pending']);

    Livewire::test(ListThreads::class)
        ->assertTableActionHidden('approveThread', $approved)
        ->callTableAction('approveThread', $pending);

    expect($pending->refresh()->moderation_status)->toBe(ContentModerationStatus::APPROVED);
});

it('deletes a thread from the table', function () {
    fModerator();
    $thread = fThread($this->forum, $this->author);

    Livewire::test(ListThreads::class)->callTableAction('deleteThread', $thread, ['reason' => 'spam']);

    expect(Thread::find($thread->id))->toBeNull();
});

it('filters threads by moderation status', function () {
    fModerator();
    $approved = fThread($this->forum, $this->author);
    $pending = fThread($this->forum, $this->author, ['moderation_status' => 'pending']);

    Livewire::test(ListThreads::class)
        ->filterTable('moderation_status', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved]);
});

it('does nothing for a user who may not moderate', function () {
    fModerator(Abilities::VIEW_UNAPPROVED);
    $thread = fThread($this->forum, $this->author);

    Livewire::test(ListThreads::class)->assertForbidden();

    expect($thread->refresh()->is_locked)->toBeFalse();
});

// ------------------------------------------------------------------ posts

it('deletes, restores, rejects and edits posts from the table', function () {
    fModerator();
    $thread = fThread($this->forum, $this->author);
    $post = fPost($thread, $this->author);
    $pending = fPost($thread, $this->author, ['moderation_status' => 'pending']);

    $test = Livewire::test(ListPosts::class)->assertCanSeeTableRecords([$post, $pending]);

    $test->callTableAction('deletePost', $post, ['reason' => 'rude']);
    expect($post->refresh()->is_deleted)->toBeTrue();

    $test->callTableAction('restorePost', $post);
    expect($post->refresh()->is_deleted)->toBeFalse();

    $test->callTableAction('rejectPost', $pending);
    expect($pending->refresh()->moderation_status)->toBe(ContentModerationStatus::REJECTED);

    $test->callTableAction('editPost', $post, ['body' => 'fixed']);
    expect($post->refresh()->body)->toBe('fixed')
        ->and(PostModerationLog::orderBy('id')->pluck('action')->all())->toBe(['delete', 'restore', 'reject']);
});

// ------------------------------------------------------------------ reports

it('lists open reports by default and resolves them with an outcome', function () {
    $mod = fModerator();
    $thread = fThread($this->forum, $this->author);
    $post = fPost($thread, $this->author);
    $report = PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $this->author->id, 'reason' => 'spam']);
    $done = PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $this->author->id, 'reason' => 'old', 'status' => 'dismissed']);

    Livewire::test(ListPostReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertCanNotSeeTableRecords([$done])
        ->callTableAction('resolveReport', $report, ['outcome' => 'remove', 'note' => 'gone'])
        ->assertNotified();

    expect($report->refresh()->status)->toBe(PostReportStatus::REVIEWED)
        ->and($report->reviewed_by_id)->toBe($mod->id)
        ->and($post->refresh()->is_deleted)->toBeTrue();
});

it('offers removing the thread only when the reported post is all it holds', function () {
    fModerator();
    $thread = fThread($this->forum, $this->author);
    $only = $thread->posts()->first();
    $report = PostReport::create(['post_id' => $only->id, 'thread_id' => $thread->id, 'reporter_id' => $this->author->id, 'reason' => 'x']);

    Livewire::test(ListPostReports::class)->callTableAction('resolveReport', $report, ['outcome' => 'remove_thread'])->assertHasNoTableActionErrors();

    expect(Thread::find($thread->id))->toBeNull();

    $busy = fThread($this->forum, $this->author);
    fPost($busy, $this->author);
    $report2 = PostReport::create(['post_id' => $busy->posts()->first()->id, 'thread_id' => $busy->id, 'reporter_id' => $this->author->id, 'reason' => 'x']);

    Livewire::test(ListPostReports::class)
        ->callTableAction('resolveReport', $report2, ['outcome' => 'remove_thread'])
        ->assertHasTableActionErrors(['outcome']);
});

// ------------------------------------------------------------------ queue

it('queues pending threads, replies to approved threads, and one row per reported reply', function () {
    fModerator();
    $pendingThread = fThread($this->forum, $this->author, ['moderation_status' => 'pending']);
    $thread = fThread($this->forum, $this->author);
    $pendingReply = fPost($thread, $this->author, ['moderation_status' => 'pending']);
    $reported = fPost($thread, $this->author);
    PostReport::create(['post_id' => $reported->id, 'thread_id' => $thread->id, 'reporter_id' => $this->author->id, 'reason' => 'a']);
    PostReport::create(['post_id' => $reported->id, 'thread_id' => $thread->id, 'reporter_id' => TestUser::make('R2')->id, 'reason' => 'b']);

    $page = Livewire::test(ModerationQueue::class)->assertSee($pendingThread->title)->assertSee($pendingReply->body);

    expect($page->instance()->pendingThreads->pluck('id')->all())->toBe([$pendingThread->id])
        ->and($page->instance()->pendingPosts->pluck('id')->all())->toBe([$pendingReply->id])
        ->and($page->instance()->reportedPosts->pluck('id')->all())->toBe([$reported->id])
        ->and($page->instance()->reportedPosts->first()->open_reports_count)->toBe(2);
});

it('acts on queued items and refreshes the queue', function () {
    fModerator();
    $pendingThread = fThread($this->forum, $this->author, ['moderation_status' => 'pending']);
    $thread = fThread($this->forum, $this->author);
    $pendingReply = fPost($thread, $this->author, ['moderation_status' => 'pending']);

    $page = Livewire::test(ModerationQueue::class)
        ->callAction('approveThread', [], ['id' => $pendingThread->id])
        ->callAction('approvePost', [], ['id' => $pendingReply->id]);

    expect($pendingThread->refresh()->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($pendingReply->refresh()->moderation_status)->toBe(ContentModerationStatus::APPROVED)
        ->and($page->instance()->pendingThreads)->toHaveCount(0)
        ->and($page->instance()->pendingPosts)->toHaveCount(0);
});

it('resolves a queued report', function () {
    fModerator();
    $thread = fThread($this->forum, $this->author);
    $post = fPost($thread, $this->author);
    $report = PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $this->author->id, 'reason' => 'x']);

    $page = Livewire::test(ModerationQueue::class)
        ->callAction('resolveReport', ['outcome' => 'dismiss', 'note' => null], ['id' => $post->id]);

    expect($report->refresh()->status)->toBe(PostReportStatus::DISMISSED)
        ->and($page->instance()->reportedPosts)->toHaveCount(0);
});

it('counts what is waiting in the dashboard widget and the reports badge', function () {
    fModerator();
    fThread($this->forum, $this->author, ['moderation_status' => 'pending']);
    $thread = fThread($this->forum, $this->author);
    fPost($thread, $this->author, ['moderation_status' => 'pending']);
    $post = fPost($thread, $this->author);
    PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $this->author->id, 'reason' => 'x']);

    Livewire::test(ModerationQueueStats::class)
        ->assertSee('1 threads and 1 replies')
        ->assertSee('1 reply with open reports');

    expect(PostReportResource::getNavigationBadge())->toBe('1');
});
