<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\ForumGroups\Pages;

use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateForumGroup extends CreateRecord
{
    protected static string $resource = ForumGroupResource::class;
}
