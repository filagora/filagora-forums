<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\Forums\Tables;

use Filagora\Forums\Enums\ForumVisibility;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ForumsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('group.name')->label(__('filagora-forums::messages.forums.table.group'))->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('visibility')->badge()->formatStateUsing(fn ($state): string => $state instanceof ForumVisibility ? $state->label() : (string) $state),
                TextColumn::make('required_entitlement_scope')->label(__('filagora-forums::messages.forums.table.entitlement'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reply_entitlement_scope')->label(__('filagora-forums::messages.forums.table.reply_entitlement'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('thread_count')->numeric()->sortable(),
                TextColumn::make('post_count')->numeric()->sortable(),
                IconColumn::make('is_read_only')->boolean(),
                IconColumn::make('requires_thread_approval')->boolean(),
                IconColumn::make('requires_reply_approval')->boolean(),
                TextColumn::make('sort_order')->numeric()->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('visibility')->options(ForumVisibility::options()),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
