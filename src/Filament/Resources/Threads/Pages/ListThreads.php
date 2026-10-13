<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Threads\Pages;

use Filagora\Forums\Filament\Resources\Threads\ThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListThreads extends ListRecords
{
    protected static string $resource = ThreadResource::class;
}
