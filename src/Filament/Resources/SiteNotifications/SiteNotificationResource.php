<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\SiteNotifications;

use BackedEnum;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Forums\Filament\Concerns\GatedByAnnouncements;
use Filagora\Forums\Filament\Resources\SiteNotifications\Pages\CreateSiteNotification;
use Filagora\Forums\Filament\Resources\SiteNotifications\Pages\EditSiteNotification;
use Filagora\Forums\Filament\Resources\SiteNotifications\Pages\ListSiteNotifications;
use Filagora\Forums\Filament\Resources\SiteNotifications\Schemas\SiteNotificationForm;
use Filagora\Forums\Filament\Resources\SiteNotifications\Tables\SiteNotificationsTable;
use Filagora\Forums\Support\Models;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SiteNotificationResource extends Resource
{
    use GatedByAnnouncements;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 60;

    public static function getModel(): string
    {
        return Models::class('site_notification');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('forum');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-forums::announcements.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-forums::announcements.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-forums::announcements.resource.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return SiteNotificationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SiteNotificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteNotifications::route('/'),
            'create' => CreateSiteNotification::route('/create'),
            'edit' => EditSiteNotification::route('/{record}/edit'),
        ];
    }
}
