<?php

declare(strict_types=1);

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Forums\Filament\Resources\Forums\Pages\CreateForum;
use Filagora\Forums\Filament\Resources\Forums\Pages\ListForums;
use Filagora\Forums\Filament\Resources\SiteNotifications\SiteNotificationResource;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\SiteNotification;
use Filagora\Forums\Services\ActiveAnnouncements;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\HostAccess;
use Filagora\Forums\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    HostAccess::$granted = [];
    app()->forgetInstance(ForumAccess::class);
});

function hostUser(string ...$keys): TestUser
{
    $user = TestUser::make('Host');
    HostAccess::$granted[$user->id] = array_values($keys);

    return $user;
}

it('runs with no entitlements package loaded, needing only core', function () {
    $module = app(ModuleRegistry::class);

    expect($module->get('forums')->requires)->toBe(['core'])
        ->and($module->has('entitlements'))->toBeFalse()
        ->and($module->missingRequirements())->toBe([])
        ->and(app()->bound(EntitlementRegistry::class))->toBeFalse();
});

it('follows the host\'s own checker for the staff abilities, and keeps announcements apart from staff', function () {
    $moderator = hostUser('filagora.forums.moderate');
    $editor = hostUser('filagora.forums.announcements');

    expect(Gate::forUser($moderator)->allows(Abilities::MODERATE))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows(Abilities::ANNOUNCEMENTS))->toBeFalse()
        ->and(Gate::forUser($editor)->allows(Abilities::ANNOUNCEMENTS))->toBeTrue()
        ->and(Gate::forUser($editor)->any(Abilities::staff()))->toBeFalse()
        ->and(app(ForumAccess::class)->isStaff($moderator))->toBeTrue()
        ->and(app(ForumAccess::class)->isStaff($editor))->toBeFalse();
});

it('asks the host\'s checker whether a user holds the key set on a forum', function () {
    $group = ForumGroup::create(['slug' => 'main', 'name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'Beta', 'slug' => 'beta', 'visibility' => 'entitlement_only', 'required_entitlement_scope' => 'plugins.beta'])->refresh();
    $holder = hostUser('plugins.beta');
    $other = hostUser();

    expect(app(ForumAccess::class)->canViewForum($forum, $holder))->toBeTrue()
        ->and(app(ForumAccess::class)->canViewForum($forum, $other))->toBeFalse();
});

it('shows the forum screens to the host\'s moderators and takes the access key as plain text', function () {
    actingAs(hostUser('filagora.forums.moderate'));
    $group = ForumGroup::create(['slug' => 'main', 'name' => 'Main']);

    Livewire::test(ListForums::class)->assertSuccessful();
    Livewire::test(CreateForum::class)
        ->assertFormFieldExists('required_entitlement_scope', fn ($field) => $field instanceof TextInput)
        ->fillForm(['forum_group_id' => $group->id, 'name' => 'Beta', 'slug' => 'beta', 'visibility' => 'entitlement_only', 'posting_policy' => 'registered', 'sort_order' => 1, 'required_entitlement_scope' => 'plugins.beta'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Forum::query()->where('slug', 'beta')->value('required_entitlement_scope'))->toBe('plugins.beta');
});

it('denies the screens to a user the host\'s checker does not allow', function () {
    actingAs(hostUser());

    Livewire::test(ListForums::class)->assertForbidden();
    expect(SiteNotificationResource::canViewAny())->toBeFalse();
});

it('targets announcements by a key through the host\'s checker', function () {
    SiteNotification::create(['title' => 'Beta news', 'body' => 'B', 'status' => 'published', 'targeting_rules' => [['type' => 'entitlement', 'entitlement' => 'plugins.beta']]]);

    expect(app(ActiveAnnouncements::class)->forViewer(hostUser('plugins.beta'))->count())->toBe(1)
        ->and(app(ActiveAnnouncements::class)->forViewer(hostUser())->count())->toBe(0)
        ->and(app(ActiveAnnouncements::class)->forViewer(null)->count())->toBe(0);
});
