<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filagora\Forums\Filament\Resources\Forums\ForumResource;

class CreateForum extends CreateRecord
{
    protected static string $resource = ForumResource::class;
}
