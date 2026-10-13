<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\PostReports\Pages;

use Filagora\Forums\Filament\Resources\PostReports\PostReportResource;
use Filament\Resources\Pages\ListRecords;

class ListPostReports extends ListRecords
{
    protected static string $resource = PostReportResource::class;
}
