<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Forums;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use ForumKit\Forums\Filament\Concerns\GatedByModerate;
use ForumKit\Forums\Filament\Resources\Forums\Pages\CreateForum;
use ForumKit\Forums\Filament\Resources\Forums\Pages\EditForum;
use ForumKit\Forums\Filament\Resources\Forums\Pages\ListForums;
use ForumKit\Forums\Filament\Resources\Forums\Schemas\ForumForm;
use ForumKit\Forums\Filament\Resources\Forums\Tables\ForumsTable;
use ForumKit\Forums\Support\Models;
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
        return __('forumkit-forums::messages.forums.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-forums::messages.forums.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-forums::messages.forums.plural_model_label');
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
