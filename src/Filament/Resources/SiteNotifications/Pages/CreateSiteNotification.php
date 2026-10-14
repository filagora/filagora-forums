<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\SiteNotifications\Pages;

use Filagora\Forums\Filament\Resources\SiteNotifications\SiteNotificationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSiteNotification extends CreateRecord
{
    protected static string $resource = SiteNotificationResource::class;
}
