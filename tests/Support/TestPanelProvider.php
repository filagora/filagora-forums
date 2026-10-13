<?php

declare(strict_types=1);

namespace Filagora\Forums\Tests\Support;

use Filament\Panel;
use Filament\PanelProvider;
use Filagora\Core\FilagoraCorePlugin;
use Filagora\Entitlements\FilagoraEntitlementsPlugin;
use Filagora\Forums\FilagoraForumsPlugin;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(FilagoraCorePlugin::make())
            ->plugin(FilagoraEntitlementsPlugin::make())
            ->plugin(FilagoraForumsPlugin::make());
    }
}
