<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\ForumGroups\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;

class CreateForumGroup extends CreateRecord
{
    protected static string $resource = ForumGroupResource::class;
}
