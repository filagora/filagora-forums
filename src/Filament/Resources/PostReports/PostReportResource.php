<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\PostReports;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use ForumKit\Core\Navigation\NavigationGroupRegistry;
use ForumKit\Forums\Enums\PostReportStatus;
use ForumKit\Forums\Filament\Concerns\GatedByModerate;
use ForumKit\Forums\Filament\Concerns\RequestMetric;
use ForumKit\Forums\Filament\Resources\PostReports\Pages\ListPostReports;
use ForumKit\Forums\Filament\Resources\PostReports\Tables\PostReportsTable;
use ForumKit\Forums\Models\PostReport;
use ForumKit\Forums\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PostReportResource extends Resource
{
    use GatedByModerate;
    use RequestMetric;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Flag;

    protected static ?int $navigationSort = 20;

    public static function getModel(): string
    {
        return Models::class('post_report');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('queues');
    }

    public static function getNavigationLabel(): string
    {
        return __('forumkit-forums::messages.reports.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forumkit-forums::messages.reports.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forumkit-forums::messages.reports.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return PostReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPostReports::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['post', 'reporter']);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::badgeCount(static::openReportCount());
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function openReportCount(): int
    {
        return static::rememberRequestMetric('open_reports', static fn (): int => PostReport::query()
            ->where('status', PostReportStatus::OPEN->value)
            ->count());
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
