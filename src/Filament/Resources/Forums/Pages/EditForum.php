<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums\Pages;

use Filagora\Forums\Filament\Resources\Forums\ForumResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditForum extends EditRecord
{
    protected static string $resource = ForumResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
