<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Threads;

use BackedEnum;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Forums\Filament\Concerns\GatedByModerate;
use Filagora\Forums\Filament\Resources\Threads\Pages\ListThreads;
use Filagora\Forums\Filament\Resources\Threads\Tables\ThreadsTable;
use Filagora\Forums\Support\Models;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
        return __('filagora-forums::messages.threads.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-forums::messages.threads.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-forums::messages.threads.plural_model_label');
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
