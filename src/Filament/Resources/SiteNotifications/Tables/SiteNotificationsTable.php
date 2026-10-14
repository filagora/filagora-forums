<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\SiteNotifications\Tables;

use Filagora\Forums\Enums\AnnouncementAudience;
use Filagora\Forums\Enums\AnnouncementSeverity;
use Filagora\Forums\Enums\AnnouncementStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SiteNotificationsTable
{
    public static function configure(Table $table): Table
    {
        $t = static fn (string $key): string => __("filagora-forums::announcements.table.{$key}");

        return $table
            ->defaultSort('priority', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => $state instanceof AnnouncementStatus ? $state->label() : (string) $state),
                TextColumn::make('severity')->badge()
                    ->color(fn ($state): string => $state instanceof AnnouncementSeverity ? $state->color() : 'gray')
                    ->formatStateUsing(fn ($state): string => $state instanceof AnnouncementSeverity ? $state->label() : (string) $state),
                TextColumn::make('audience')->badge()->formatStateUsing(fn ($state): string => $state instanceof AnnouncementAudience ? $state->label() : (string) $state),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable(),
                TextColumn::make('priority')->numeric()->sortable(),
                IconColumn::make('is_dismissible')->label($t('dismissible'))->boolean(),
                IconColumn::make('is_persistent')->label($t('persistent'))->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(AnnouncementStatus::options()),
                SelectFilter::make('severity')->options(AnnouncementSeverity::options()),
                SelectFilter::make('audience')->options(AnnouncementAudience::options()),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
