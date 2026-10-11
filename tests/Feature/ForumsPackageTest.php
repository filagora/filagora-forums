<?php

declare(strict_types=1);

use Filament\Panel;
use ForumKit\Core\Modules\ModuleRegistry;
use ForumKit\Forums\ForumKitForumsPlugin;

it('registers itself as a module that needs core and entitlements', function () {
    $module = app(ModuleRegistry::class)->get('forums');

    expect($module->package)->toBe('zcwilt/forumkit-forums')
        ->and($module->requires)->toBe(['core', 'entitlements'])
        ->and(app(ModuleRegistry::class)->missingRequirements())->toBe([]);
});

it('registers on a panel', function () {
    $panel = Panel::make()->id('admin')->plugin(ForumKitForumsPlugin::make());

    expect($panel->hasPlugin('forumkit-forums'))->toBeTrue();
});

it('passes the ForumKit doctor', function () {
    $this->artisan('forumkit:doctor')->expectsOutputToContain('All checks passed.')->assertExitCode(0);
});

it('adds its models to the configuration by default', function () {
    expect(array_keys(config('forumkit-forums.models')))->toBe(['forum_group', 'forum', 'thread', 'post', 'post_report', 'post_moderation_log', 'thread_moderation_log']);
});
