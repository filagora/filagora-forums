<?php

declare(strict_types=1);

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Forums\FilagoraForumsPlugin;
use Filament\Panel;

it('registers itself as a module that needs core and entitlements', function () {
    $module = app(ModuleRegistry::class)->get('forums');

    expect($module->package)->toBe('filagora/filagora-forums')
        ->and($module->requires)->toBe(['core', 'entitlements'])
        ->and(app(ModuleRegistry::class)->missingRequirements())->toBe([]);
});

it('registers on a panel', function () {
    $panel = Panel::make()->id('admin')->plugin(FilagoraForumsPlugin::make());

    expect($panel->hasPlugin('filagora-forums'))->toBeTrue();
});

it('passes the Filagora doctor', function () {
    $this->artisan('filagora:doctor')->expectsOutputToContain('All checks passed.')->assertExitCode(0);
});

it('adds its models to the configuration by default', function () {
    expect(array_keys(config('filagora-forums.models')))->toBe(['forum_group', 'forum', 'thread', 'post', 'post_report', 'post_moderation_log', 'thread_moderation_log']);
});
