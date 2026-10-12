<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Forums\Pages;

use Filament\Resources\Pages\CreateRecord;
use ForumKit\Forums\Filament\Resources\Forums\ForumResource;

class CreateForum extends CreateRecord
{
    protected static string $resource = ForumResource::class;
}
