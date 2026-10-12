<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\ForumGroups\Pages;

use Filament\Resources\Pages\CreateRecord;
use ForumKit\Forums\Filament\Resources\ForumGroups\ForumGroupResource;

class CreateForumGroup extends CreateRecord
{
    protected static string $resource = ForumGroupResource::class;
}
