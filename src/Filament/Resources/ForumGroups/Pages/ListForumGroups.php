<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\ForumGroups\Pages;

use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListForumGroups extends ListRecords
{
    protected static string $resource = ForumGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
