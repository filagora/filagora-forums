<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Forums\Filament\Concerns\GatedByModerate;
use Filagora\Forums\Filament\Resources\Forums\Pages\CreateForum;
use Filagora\Forums\Filament\Resources\Forums\Pages\EditForum;
use Filagora\Forums\Filament\Resources\Forums\Pages\ListForums;
use Filagora\Forums\Filament\Resources\Forums\Schemas\ForumForm;
use Filagora\Forums\Filament\Resources\Forums\Tables\ForumsTable;
use Filagora\Forums\Support\Models;
use UnitEnum;

class ForumResource extends Resource
{
    use GatedByModerate;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 20;

    public static function getModel(): string
    {
        return Models::class('forum');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('forum');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-forums::messages.forums.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-forums::messages.forums.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-forums::messages.forums.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ForumForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ForumsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForums::route('/'),
            'create' => CreateForum::route('/create'),
            'edit' => EditForum::route('/{record}/edit'),
        ];
    }
}
