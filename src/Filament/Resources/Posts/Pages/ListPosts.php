<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Posts\Pages;

use Filament\Resources\Pages\ListRecords;
use ForumKit\Forums\Filament\Resources\Posts\PostResource;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
