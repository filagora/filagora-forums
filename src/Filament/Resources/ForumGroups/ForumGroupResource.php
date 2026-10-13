<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\ForumGroups;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Forums\Filament\Concerns\GatedByModerate;
use Filagora\Forums\Filament\Resources\ForumGroups\Pages\CreateForumGroup;
use Filagora\Forums\Filament\Resources\ForumGroups\Pages\EditForumGroup;
use Filagora\Forums\Filament\Resources\ForumGroups\Pages\ListForumGroups;
use Filagora\Forums\Filament\Resources\ForumGroups\Schemas\ForumGroupForm;
use Filagora\Forums\Filament\Resources\ForumGroups\Tables\ForumGroupsTable;
use Filagora\Forums\Support\Models;
use UnitEnum;

class ForumGroupResource extends Resource
{
    use GatedByModerate;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 10;

    public static function getModel(): string
    {
        return Models::class('forum_group');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('forum');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-forums::messages.groups.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-forums::messages.groups.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-forums::messages.groups.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ForumGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ForumGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForumGroups::route('/'),
            'create' => CreateForumGroup::route('/create'),
            'edit' => EditForumGroup::route('/{record}/edit'),
        ];
    }
}
