<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Threads;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use ForumKit\Forums\Filament\Concerns\GatedByModerate;
use ForumKit\Forums\Filament\Resources\Threads\Pages\ListThreads;
use ForumKit\Forums\Filament\Resources\Threads\Tables\ThreadsTable;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ThreadResource extends Resource
{
    use GatedByModerate;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChatBubbleLeft;

    protected static ?int $navigationSort = 30;

    public static function getModel(): string
    {
        return Models::class('thread');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('forum');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-forums::messages.threads.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-forums::messages.threads.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-forums::messages.threads.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return ThreadsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListThreads::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['forum', 'author']);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
