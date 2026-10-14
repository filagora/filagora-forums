<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\SiteNotifications\Pages;

use Filagora\Forums\Filament\Resources\SiteNotifications\SiteNotificationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSiteNotification extends EditRecord
{
    protected static string $resource = SiteNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
