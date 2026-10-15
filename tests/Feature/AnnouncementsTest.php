<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\AnnouncementAudience;
use Filagora\Forums\Enums\AnnouncementSeverity;
use Filagora\Forums\Enums\AnnouncementStatus;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\SiteNotification;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ActiveAnnouncements;
use Filagora\Forums\Services\AnnouncementDismissals;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\TargetingRules;
use Filagora\Forums\Targeting\Viewer;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

function annNotice(array $attributes = []): SiteNotification
{
    return SiteNotification::create(array_merge(['title' => 'Notice '.uniqid(), 'body' => 'Body', 'status' => 'published'], $attributes))->refresh();
}

function annPost(TestUser $author, ?string $at = null): Post
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::query()->firstOrCreate(['slug' => 'general'], ['forum_group_id' => $group->id, 'name' => 'General']);
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't-'.uniqid()]);
    $post = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Body']);

    if ($at !== null) {
        $post->newQuery()->whereKey($post->id)->toBase()->update(['created_at' => $at]);
    }

    return $post;
}

function annIds(?TestUser $user = null, array $context = []): array
{
    return app(ActiveAnnouncements::class)->forViewer($user, $context)->pluck('id')->all();
}

function annRule(array $rule, ?TestUser $user = null, array $context = []): bool
{
    return app(TargetingRules::class)->passes([$rule], new Viewer($user, $context));
}

it('keeps every stored enum value, with labels and colours', function () {
    expect(array_column(AnnouncementStatus::cases(), 'value'))->toBe(['draft', 'published'])
        ->and(array_column(AnnouncementAudience::cases(), 'value'))->toBe(['everyone', 'guests_only', 'authenticated_only'])
        ->and(array_column(AnnouncementSeverity::cases(), 'value'))->toBe(['info', 'success', 'warning', 'critical'])
        ->and(AnnouncementSeverity::CRITICAL->color())->toBe('danger')
        ->and(AnnouncementAudience::options())->toHaveKey('guests_only', 'Guests only');

    foreach (['guests_only', 'authenticated_only', 'everyone'] as $value) {
        foreach (['info', 'success', 'warning', 'critical'] as $severity) {
            $notice = annNotice(['audience' => $value, 'severity' => $severity, 'status' => 'draft']);

            expect($notice->audience->value)->toBe($value)->and($notice->severity->value)->toBe($severity)->and($notice->status)->toBe(AnnouncementStatus::DRAFT);
        }
    }
});

it('defaults a new announcement as the live table does', function () {
    $notice = SiteNotification::create(['title' => 'T', 'body' => 'B'])->refresh();

    expect($notice->status)->toBe(AnnouncementStatus::DRAFT)
        ->and($notice->audience)->toBe(AnnouncementAudience::EVERYONE)
        ->and($notice->severity)->toBe(AnnouncementSeverity::INFO)
        ->and($notice->priority)->toBe(0)
        ->and($notice->is_dismissible)->toBeTrue()
        ->and($notice->is_persistent)->toBeTrue()
        ->and($notice->targeting_rules)->toBeNull();
});

it('is active only when published and inside its window, an open end meaning no limit', function () {
    $live = annNotice();
    $draft = annNotice(['status' => 'draft']);
    $future = annNotice(['starts_at' => now()->addHour()]);
    $past = annNotice(['ends_at' => now()->subHour()]);
    $window = annNotice(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

    expect(SiteNotification::query()->active()->pluck('id')->all())->toEqualCanonicalizing([$live->id, $window->id]);
    expect([$draft->id, $future->id, $past->id])->each->not->toBeIn(SiteNotification::query()->active()->pluck('id')->all());
});

it('shows each viewer the announcements for their audience', function () {
    $everyone = annNotice();
    $guests = annNotice(['audience' => 'guests_only']);
    $members = annNotice(['audience' => 'authenticated_only']);
    $user = TestUser::make('Member');

    expect(annIds())->toEqualCanonicalizing([$everyone->id, $guests->id])
        ->and(annIds($user))->toEqualCanonicalizing([$everyone->id, $members->id]);
});

it('orders by priority, then the later start first', function () {
    $low = annNotice(['priority' => 1, 'starts_at' => now()->subDay()]);
    $high = annNotice(['priority' => 9, 'starts_at' => now()->subDays(3)->setTime(12, 0)]);
    $newer = annNotice(['priority' => 1, 'starts_at' => now()->subHour()]);

    expect(annIds())->toBe([$high->id, $newer->id, $low->id]);
});

it('caches only the ids, and a save or delete shows at once', function () {
    $first = annNotice();
    annIds();

    expect(Cache::get(ActiveAnnouncements::CACHE_KEY))->toBe([$first->id]);

    $second = annNotice(['priority' => 5]);
    expect(annIds())->toBe([$second->id, $first->id]);

    $second->delete();
    expect(Cache::has(ActiveAnnouncements::CACHE_KEY))->toBeFalse()
        ->and(annIds())->toBe([$first->id]);

    $first->update(['status' => 'draft']);
    expect(annIds())->toBe([]);
});

it('applies targeting rules per viewer, all of them having to pass', function () {
    $user = TestUser::make('Member');
    $user->username = 'bob';
    $both = annNotice(['targeting_rules' => [['type' => 'username', 'value' => 'bob'], ['type' => 'never_posted']]]);
    $either = annNotice(['targeting_rules' => [['type' => 'username', 'value' => 'bob'], ['type' => 'username', 'value' => 'alice']]]);

    expect(annIds($user))->toContain($both->id)->not->toContain($either->id);

    annPost($user);

    expect(annIds($user))->not->toContain($both->id);
});

it('ignores a rule that is not an array and passes a rule of an unknown type', function () {
    $notice = annNotice(['targeting_rules' => ['junk', ['type' => 'removed_rule_type'], ['no' => 'type'], []]]);

    expect(annIds())->toContain($notice->id)
        ->and(app(TargetingRules::class)->passes(null, new Viewer))->toBeTrue()
        ->and(app(TargetingRules::class)->passes([], new Viewer))->toBeTrue();
});

it('lets a host register its own rule', function () {
    app(TargetingRules::class)->register(new class implements TargetingRule
    {
        public function type(): string
        {
            return 'on_tuesday';
        }

        public function label(): string
        {
            return 'On a Tuesday';
        }

        public function schema(): array
        {
            return [];
        }

        public function matches(array $rule, Viewer $viewer): bool
        {
            return $viewer->now()->isTuesday();
        }
    });

    $this->travelTo(now()->next('Tuesday'));
    expect(annRule(['type' => 'on_tuesday']))->toBeTrue()
        ->and(app(TargetingRules::class)->options())->toHaveKey('on_tuesday', 'On a Tuesday');

    $this->travelTo(now()->next('Wednesday'));
    expect(annRule(['type' => 'on_tuesday']))->toBeFalse();
});

it('ships the eight rules', function () {
    expect(array_keys(app(TargetingRules::class)->all()))->toBe([
        'entitlement', 'username', 'date_is', 'time_between', 'never_posted', 'post_count_between', 'not_posted_days', 'browsing_forum',
    ]);
});

it('checks entitlements, with negation, and never for a guest', function () {
    $holder = TestUser::make('Holder');
    UserEntitlementGrant::create(['user_id' => $holder->id, 'entitlement_key' => 'plugins.beta', 'grant_type' => 'manual']);
    $other = TestUser::make('Other');

    expect(annRule(['type' => 'entitlement', 'entitlement' => 'plugins.beta'], $holder))->toBeTrue()
        ->and(annRule(['type' => 'entitlement', 'entitlement' => 'plugins.beta'], $other))->toBeFalse()
        ->and(annRule(['type' => 'entitlement', 'entitlement' => 'plugins.beta', 'negate' => true], $other))->toBeTrue()
        ->and(annRule(['type' => 'entitlement', 'entitlement' => 'plugins.beta', 'negate' => true], $holder))->toBeFalse()
        ->and(annRule(['type' => 'entitlement', 'entitlement' => 'plugins.beta', 'negate' => true]))->toBeFalse()
        ->and(annRule(['type' => 'entitlement', 'entitlement' => ' '], $other))->toBeTrue();
});

it('matches a username case-insensitively, from the configured attribute', function () {
    $user = TestUser::make('Member');
    $user->username = 'Bob';

    expect(annRule(['type' => 'username', 'value' => 'bOB'], $user))->toBeTrue()
        ->and(annRule(['type' => 'username', 'value' => 'alice'], $user))->toBeFalse()
        ->and(annRule(['type' => 'username', 'value' => 'bob']))->toBeFalse()
        ->and(annRule(['type' => 'username', 'value' => ''], $user))->toBeTrue();

    config(['filagora-forums.announcements.username_attribute' => 'name']);
    expect(annRule(['type' => 'username', 'value' => 'member'], $user))->toBeTrue();
});

it('counts posts for the never-posted and post-count rules', function () {
    $user = TestUser::make('Member');

    expect(annRule(['type' => 'never_posted'], $user))->toBeTrue()
        ->and(annRule(['type' => 'never_posted']))->toBeFalse()
        ->and(annRule(['type' => 'post_count_between', 'min' => 1], $user))->toBeFalse()
        ->and(annRule(['type' => 'post_count_between', 'max' => 0], $user))->toBeTrue();

    annPost($user);
    annPost($user);
    annPost(TestUser::make('Someone else'));

    expect(annRule(['type' => 'never_posted'], $user))->toBeFalse()
        ->and(annRule(['type' => 'post_count_between', 'min' => 2, 'max' => 2], $user))->toBeTrue()
        ->and(annRule(['type' => 'post_count_between', 'min' => 3], $user))->toBeFalse()
        ->and(annRule(['type' => 'post_count_between', 'max' => 1], $user))->toBeFalse()
        ->and(annRule(['type' => 'post_count_between', 'min' => '', 'max' => ''], $user))->toBeTrue()
        ->and(annRule(['type' => 'post_count_between', 'min' => 0]))->toBeFalse();
});

it('passes the not-posted rule only for a user whose last post is old enough', function () {
    $never = TestUser::make('Never');
    $recent = TestUser::make('Recent');
    $old = TestUser::make('Old');
    annPost($recent, now()->subDays(2)->setTime(12, 0)->toDateTimeString());
    annPost($old, now()->subDays(40)->setTime(12, 0)->toDateTimeString());
    annPost($old, now()->subDays(3)->setTime(12, 0)->toDateTimeString());
    $dormant = TestUser::make('Dormant');
    annPost($dormant, now()->subDays(40)->setTime(12, 0)->toDateTimeString());

    $rule = ['type' => 'not_posted_days', 'days' => 30];

    expect(annRule($rule, $never))->toBeFalse()
        ->and(annRule($rule, $recent))->toBeFalse()
        ->and(annRule($rule, $old))->toBeFalse()
        ->and(annRule($rule, $dormant))->toBeTrue()
        ->and(annRule($rule))->toBeFalse()
        ->and(annRule(['type' => 'not_posted_days', 'days' => 0], $recent))->toBeTrue();
});

it('passes the browsing-forum rule on that forum only, as the host reports it', function () {
    $rule = ['type' => 'browsing_forum', 'forum_id' => '7'];

    expect(annRule($rule, null, ['forum_id' => 7]))->toBeTrue()
        ->and(annRule($rule, null, ['forum_id' => 8]))->toBeFalse()
        ->and(annRule($rule))->toBeFalse()
        ->and(annRule(['type' => 'browsing_forum']))->toBeTrue();
});

it('reads the date and the time of day on the viewer\'s own clock', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-25 23:30:00', 'UTC'));

    $tokyo = TestUser::make('Tokyo');
    $tokyo->timezone = 'Asia/Tokyo';

    expect(annRule(['type' => 'date_is', 'month' => 12, 'day' => 25]))->toBeTrue()
        ->and(annRule(['type' => 'date_is', 'month' => 12, 'day' => 26], $tokyo))->toBeTrue()
        ->and(annRule(['type' => 'date_is', 'month' => 12, 'day' => 25], $tokyo))->toBeFalse()
        ->and(annRule(['type' => 'date_is', 'month' => 0, 'day' => 0]))->toBeTrue()
        ->and(annRule(['type' => 'time_between', 'start' => '23:00', 'end' => '23:59']))->toBeTrue()
        ->and(annRule(['type' => 'time_between', 'start' => '23:00', 'end' => '23:59'], $tokyo))->toBeFalse()
        ->and(annRule(['type' => 'time_between', 'start' => '08:00', 'end' => '09:00']))->toBeFalse()
        ->and(annRule(['type' => 'time_between', 'start' => '', 'end' => '09:00']))->toBeTrue();
});

it('runs an overnight time window across midnight', function () {
    $rule = ['type' => 'time_between', 'start' => '22:00', 'end' => '02:00'];

    foreach (['2026-01-01 23:00' => true, '2026-01-01 01:30' => true, '2026-01-01 12:00' => false, '2026-01-01 03:00' => false] as $at => $expected) {
        $this->travelTo(CarbonImmutable::parse($at, config('app.timezone')));

        expect(annRule($rule))->toBe($expected, $at);
    }
});

it('lets a user dismiss only what allows it, once', function () {
    $user = TestUser::make('Member');
    $dismissals = app(AnnouncementDismissals::class);
    $closable = annNotice();
    $fixed = annNotice(['is_dismissible' => false]);

    expect($dismissals->dismiss($user, $closable->id))->toBeTrue()
        ->and($dismissals->dismiss($user, $closable->id))->toBeTrue()
        ->and($dismissals->dismiss($user, $fixed->id))->toBeFalse()
        ->and($dismissals->dismiss($user, 999999))->toBeFalse()
        ->and($dismissals->dismissedIdsFor($user))->toBe([$closable->id])
        ->and($dismissals->dismissedIdsFor(TestUser::make('Other')))->toBe([]);
});

it('expires a non-persistent announcement on first view, whether or not it can be dismissed', function () {
    $user = TestUser::make('Member');
    $dismissals = app(AnnouncementDismissals::class);
    $once = annNotice(['is_persistent' => false, 'is_dismissible' => false]);
    $always = annNotice();

    expect($dismissals->recordFirstView($user, $once->id))->toBeTrue()
        ->and($dismissals->recordFirstView($user, $always->id))->toBeFalse()
        ->and($dismissals->recordFirstView($user, 999999))->toBeFalse()
        ->and($dismissals->dismissedIdsFor($user))->toBe([$once->id]);
});

it('forgets dismissals with the announcement, and with the user', function () {
    $user = TestUser::make('Member');
    $kept = TestUser::make('Kept');
    $notice = annNotice();
    $dismissals = app(AnnouncementDismissals::class);
    $dismissals->dismiss($user, $notice->id);
    $dismissals->dismiss($kept, $notice->id);

    $user->delete();
    expect($dismissals->dismissedIdsFor($kept))->toBe([$notice->id]);

    $notice->delete();
    expect($dismissals->dismissedIdsFor($kept))->toBe([]);
});

it('gates the announcements ability on its entitlement without making the holder forum staff', function () {
    $editor = TestUser::make('Editor');
    expect(Gate::forUser($editor)->allows(Abilities::ANNOUNCEMENTS))->toBeFalse();

    UserEntitlementGrant::create(['user_id' => $editor->id, 'entitlement_key' => 'filagora.forums.announcements', 'grant_type' => 'manual']);

    expect(Gate::forUser($editor)->allows(Abilities::ANNOUNCEMENTS))->toBeTrue()
        ->and(Gate::forUser($editor)->any(Abilities::staff()))->toBeFalse()
        ->and(Abilities::staff())->not->toContain(Abilities::ANNOUNCEMENTS);
});
