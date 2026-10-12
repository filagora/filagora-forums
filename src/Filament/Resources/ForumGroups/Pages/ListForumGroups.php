<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\ForumGroups\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use ForumKit\Forums\Filament\Resources\ForumGroups\ForumGroupResource;

class ListForumGroups extends ListRecords
{
    protected static string $resource = ForumGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
