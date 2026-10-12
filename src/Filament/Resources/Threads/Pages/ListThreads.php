<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Threads\Pages;

use Filament\Resources\Pages\ListRecords;
use ForumKit\Forums\Filament\Resources\Threads\ThreadResource;

class ListThreads extends ListRecords
{
    protected static string $resource = ThreadResource::class;
}
