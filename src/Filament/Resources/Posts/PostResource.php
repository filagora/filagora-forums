<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Posts;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Forums\Filament\Concerns\GatedByModerate;
use Filagora\Forums\Filament\Resources\Posts\Pages\ListPosts;
use Filagora\Forums\Filament\Resources\Posts\Tables\PostsTable;
use Filagora\Forums\Support\Models;
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
        return __('filagora-forums::messages.posts.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-forums::messages.posts.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-forums::messages.posts.plural_model_label');
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
