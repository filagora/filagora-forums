<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\AnnouncementStatus;
use Filagora\Forums\Filament\Resources\SiteNotifications\Pages\CreateSiteNotification;
use Filagora\Forums\Filament\Resources\SiteNotifications\Pages\EditSiteNotification;
use Filagora\Forums\Filament\Resources\SiteNotifications\Pages\ListSiteNotifications;
use Filagora\Forums\Filament\Resources\SiteNotifications\SiteNotificationResource;
use Filagora\Forums\Models\SiteNotification;
use Filagora\Forums\Services\ActiveAnnouncements;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function annEditor(string $key = Abilities::ANNOUNCEMENTS): TestUser
{
    $user = TestUser::make('Editor');
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);
    actingAs($user);

    return $user;
}

function screenNotice(array $attributes = []): SiteNotification
{
    return SiteNotification::create(array_merge(['title' => 'Notice', 'body' => 'Body', 'status' => 'published'], $attributes))->refresh();
}

it('opens the announcements screen to the ability only, not to moderators or guests', function () {
    expect(SiteNotificationResource::canViewAny())->toBeFalse();

    annEditor(Abilities::MODERATE);
    expect(SiteNotificationResource::canViewAny())->toBeFalse();
    Livewire::test(ListSiteNotifications::class)->assertForbidden();

    annEditor();
    expect(SiteNotificationResource::canViewAny())->toBeTrue()
        ->and(SiteNotificationResource::canCreate())->toBeTrue();
});

it('creates an announcement as a draft by default, with its rules', function () {
    annEditor();

    Livewire::test(CreateSiteNotification::class)
        ->fillForm(['title' => 'Maintenance', 'body' => 'Down **tonight**', 'targeting_rules' => [['type' => 'username', 'value' => 'bob']]])
        ->call('create')
        ->assertHasNoFormErrors();

    $notice = SiteNotification::query()->sole();

    expect($notice->status)->toBe(AnnouncementStatus::DRAFT)
        ->and($notice->title)->toBe('Maintenance')
        ->and($notice->targeting_rules)->toBe([['type' => 'username', 'value' => 'bob']])
        ->and($notice->is_dismissible)->toBeTrue();
});

it('requires a title and body, and an end after the start', function () {
    annEditor();

    Livewire::test(CreateSiteNotification::class)
        ->fillForm(['title' => '', 'body' => ''])
        ->call('create')
        ->assertHasFormErrors(['title' => 'required', 'body' => 'required']);

    Livewire::test(CreateSiteNotification::class)
        ->fillForm(['title' => 'T', 'body' => 'B', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->toDateTimeString()])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);
});

it('saves only the values of the rule type that is picked', function () {
    annEditor();

    Livewire::test(CreateSiteNotification::class)
        ->fillForm(['title' => 'T', 'body' => 'B', 'targeting_rules' => [
            ['type' => 'not_posted_days', 'days' => 30, 'forum_id' => 4, 'value' => 'stale'],
            ['type' => 'never_posted', 'negate' => true],
        ]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SiteNotification::query()->sole()->targeting_rules)->toBe([['type' => 'not_posted_days', 'days' => 30], ['type' => 'never_posted']]);
});

it('edits an announcement and the change shows at once', function () {
    annEditor();
    $notice = screenNotice();
    $viewer = TestUser::make('Visitor');
    expect(app(ActiveAnnouncements::class)->forViewer($viewer)->pluck('title')->all())->toBe(['Notice']);

    Livewire::test(EditSiteNotification::class, ['record' => $notice->getKey()])
        ->assertFormSet(['title' => 'Notice', 'status' => 'published'])
        ->fillForm(['title' => 'Renamed', 'status' => 'draft'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($notice->refresh()->title)->toBe('Renamed')
        ->and(app(ActiveAnnouncements::class)->forViewer($viewer)->all())->toBe([]);
});

it('deletes an announcement from the edit page', function () {
    annEditor();
    $notice = screenNotice();

    Livewire::test(EditSiteNotification::class, ['record' => $notice->getKey()])->callAction('delete');

    expect(SiteNotification::query()->count())->toBe(0);
});

it('lists announcements and filters them by status', function () {
    annEditor();
    $live = screenNotice(['title' => 'Live']);
    $draft = screenNotice(['title' => 'Draft', 'status' => 'draft']);

    Livewire::test(ListSiteNotifications::class)
        ->assertCanSeeTableRecords([$live, $draft])
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$live]);
});
