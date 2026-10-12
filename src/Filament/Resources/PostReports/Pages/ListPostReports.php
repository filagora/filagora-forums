<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\PostReports\Pages;

use Filament\Resources\Pages\ListRecords;
use ForumKit\Forums\Filament\Resources\PostReports\PostReportResource;

class ListPostReports extends ListRecords
{
    protected static string $resource = PostReportResource::class;
}
