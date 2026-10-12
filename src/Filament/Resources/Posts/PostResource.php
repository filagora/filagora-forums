<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Posts;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use ForumKit\Forums\Filament\Concerns\GatedByModerate;
use ForumKit\Forums\Filament\Resources\Posts\Pages\ListPosts;
use ForumKit\Forums\Filament\Resources\Posts\Tables\PostsTable;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PostResource extends Resource
{
    use GatedByModerate;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?int $navigationSort = 40;

    public static function getModel(): string
    {
        return Models::class('post');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('forum');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-forums::messages.posts.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-forums::messages.posts.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-forums::messages.posts.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return PostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPosts::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['thread', 'author']);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
