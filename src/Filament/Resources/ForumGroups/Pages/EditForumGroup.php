<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\ForumGroups\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use ForumKit\Forums\Filament\Resources\ForumGroups\ForumGroupResource;

class EditForumGroup extends EditRecord
{
    protected static string $resource = ForumGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
