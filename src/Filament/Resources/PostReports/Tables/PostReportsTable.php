<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Resources\PostReports\Tables;

use Filagora\Core\Support\UserModel;
use Filagora\Forums\Enums\PostReportStatus;
use Filagora\Forums\Filament\Actions\Moderate;
use Filagora\Forums\Filament\Actions\ResolveReportAction;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostReport;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PostReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('post.body')->label(__('filagora-forums::messages.reports.table.post'))->limit(60),
                TextColumn::make('reason')->limit(60)->searchable(),
                TextColumn::make('reporter.'.UserModel::titleAttribute())->label(__('filagora-forums::messages.reports.table.reporter'))->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof PostReportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match ($state) {
                        PostReportStatus::OPEN => 'warning',
                        PostReportStatus::REVIEWED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('resolution_note')->placeholder('-')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(PostReportStatus::options())->default(PostReportStatus::OPEN->value),
            ])
            ->recordActions([
                Action::make('previewPost')
                    ->label(Moderate::t('read'))
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (PostReport $record): HtmlString => new HtmlString('<div class="p-4 whitespace-pre-wrap">'.e($record->post instanceof Post ? $record->post->body : '').'</div>')),
                ResolveReportAction::make(),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells);
    }
}
