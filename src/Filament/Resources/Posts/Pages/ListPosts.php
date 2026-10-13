<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Posts\Pages;

use Filagora\Forums\Filament\Resources\Posts\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
