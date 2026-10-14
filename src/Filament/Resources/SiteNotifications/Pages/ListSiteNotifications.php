<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\SiteNotifications\Pages;

use Filagora\Forums\Filament\Resources\SiteNotifications\SiteNotificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSiteNotifications extends ListRecords
{
    protected static string $resource = SiteNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
