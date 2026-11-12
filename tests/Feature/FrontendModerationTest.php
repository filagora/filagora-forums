<?php

declare(strict_types=1);

use Filagora\Core\Support\Abilities as CoreAbilities;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Exceptions\ModerationNotPermitted;
use Filagora\Forums\Filament\Pages\ModerationQueue;
use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;
use Filagora\Forums\Filament\Resources\Forums\ForumResource;
use Filagora\Forums\Filament\Resources\PostReports\PostReportResource;
use Filagora\Forums\Filament\Resources\Posts\PostResource;
use Filagora\Forums\Filament\Resources\Threads\ThreadResource;
use Filagora\Forums\Filament\Widgets\ModerationQueueStats;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\ThreadModeration;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    foreach ([ForumAccess::class, ThreadModeration::class] as $service) {
        app()->forgetInstance($service);
    }

    $this->author = TestUser::make('Author');
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $this->forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'General', 'slug' => 'general'])->refresh();
});

function frontendUser(string $key): TestUser
{
    $user = TestUser::make('Mod '.uniqid());
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);
    actingAs($user);

    return $user;
}

it('lets a frontend-only moderator moderate and counts them as staff', function () {
    $user = frontendUser(CoreAbilities::MODERATE_FRONTEND);
    $thread = Thread::create(['forum_id' => $this->forum->id, 'author_id' => $this->author->id, 'title' => 'Hello', 'slug' => 'hello'])->refresh();

    expect(app(ForumAccess::class)->canModerate($user))->toBeTrue()
        ->and(app(ForumAccess::class)->isStaff($user))->toBeTrue();

    app(ThreadModeration::class)->lock($user, $thread);

    expect($thread->refresh()->is_locked)->toBeTrue();
});

it('keeps every dashboard screen closed to a frontend-only moderator', function () {
    frontendUser(CoreAbilities::MODERATE_FRONTEND);

    foreach ([ForumGroupResource::class, ForumResource::class, ThreadResource::class, PostResource::class, PostReportResource::class] as $resource) {
        expect($resource::canViewAny())->toBeFalse($resource);
    }

    expect(Gate::allows(Abilities::MODERATE))->toBeFalse()
        ->and(ModerationQueue::canAccess())->toBeFalse()
        ->and(ModerationQueueStats::canView())->toBeFalse();
});

it('leaves full moderators and everyone else as they were', function () {
    $full = frontendUser(Abilities::MODERATE);
    expect(app(ForumAccess::class)->canModerate($full))->toBeTrue()
        ->and(ForumResource::canViewAny())->toBeTrue();

    $other = frontendUser(Abilities::VIEW_UNAPPROVED);
    $thread = Thread::create(['forum_id' => $this->forum->id, 'author_id' => $this->author->id, 'title' => 'Other', 'slug' => 'other'])->refresh();

    expect(app(ForumAccess::class)->canModerate($other))->toBeFalse()
        ->and(fn () => app(ThreadModeration::class)->lock($other, $thread))->toThrow(ModerationNotPermitted::class);
});
