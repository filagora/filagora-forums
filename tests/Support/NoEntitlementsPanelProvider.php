<?php

declare(strict_types=1);

namespace Filagora\Forums\Tests\Support;

use Filagora\Core\FilagoraCorePlugin;
use Filagora\Forums\FilagoraForumsPlugin;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * A panel for a host that has no entitlements package: core and forums only.
 */
class NoEntitlementsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilagoraCorePlugin::make())
            ->plugin(FilagoraForumsPlugin::make());
    }
}
