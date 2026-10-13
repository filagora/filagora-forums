<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\ForumGroups\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filagora\Forums\Filament\Resources\ForumGroups\ForumGroupResource;

class EditForumGroup extends EditRecord
{
    protected static string $resource = ForumGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
