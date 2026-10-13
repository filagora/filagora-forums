<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Threads\Tables;

use Filagora\Core\Support\UserModel;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Filament\Actions\ThreadActions;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ThreadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_post_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->limit(60),
                TextColumn::make('forum.name')->searchable()->sortable(),
                TextColumn::make('author.'.UserModel::titleAttribute())->label(__('filagora-forums::messages.threads.table.author'))->searchable(),
                TextColumn::make('moderation_status')
                    ->badge()
                    ->color(fn ($state): string => $state instanceof ContentModerationStatus ? $state->color() : 'gray')
                    ->formatStateUsing(fn ($state): string => $state instanceof ContentModerationStatus ? $state->label() : (string) $state),
                TextColumn::make('reply_count')->numeric()->sortable(),
                IconColumn::make('is_pinned')->boolean(),
                IconColumn::make('is_locked')->boolean(),
                TextColumn::make('last_post_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('moderation_status')->options(ContentModerationStatus::options()),
                TernaryFilter::make('is_locked'),
                TernaryFilter::make('is_pinned'),
            ])
            ->recordActions([ActionGroup::make(ThreadActions::all())])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells);
    }
}
