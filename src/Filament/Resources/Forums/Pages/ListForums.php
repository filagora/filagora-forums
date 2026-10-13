<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filagora\Forums\Filament\Resources\Forums\ForumResource;

class ListForums extends ListRecords
{
    protected static string $resource = ForumResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
