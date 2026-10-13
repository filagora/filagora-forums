<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filagora\Forums\Filament\Resources\Forums\ForumResource;

class EditForum extends EditRecord
{
    protected static string $resource = ForumResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
