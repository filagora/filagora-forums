<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Resources\Posts\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use ForumKit\Core\Support\UserModel;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Exceptions\PostingNotPermitted;
use ForumKit\Forums\Filament\Actions\Moderate;
use ForumKit\Forums\Filament\Actions\PostActions;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Services\Posting;
use Illuminate\Support\HtmlString;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('body')->limit(70)->searchable(),
                TextColumn::make('thread.title')->limit(40)->searchable(),
                TextColumn::make('author.'.UserModel::titleAttribute())->label(__('forumkit-forums::messages.threads.table.author'))->searchable(),
                TextColumn::make('moderation_status')
                    ->badge()
                    ->color(fn ($state): string => $state instanceof ContentModerationStatus ? $state->color() : 'gray')
                    ->formatStateUsing(fn ($state): string => $state instanceof ContentModerationStatus ? $state->label() : (string) $state),
                IconColumn::make('is_deleted')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('moderation_status')->options(ContentModerationStatus::options()),
                TernaryFilter::make('is_deleted'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('read')
                        ->label(Moderate::t('read'))
                        ->icon('heroicon-o-eye')
                        ->modalSubmitAction(false)
                        ->modalContent(fn (Post $record): HtmlString => new HtmlString('<div class="p-4 whitespace-pre-wrap">'.e($record->body).'</div>')),
                    self::edit(),
                    ...PostActions::all(),
                ]),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells);
    }

    private static function edit(): Action
    {
        return Action::make('editPost')
            ->label(Moderate::t('edit'))
            ->icon('heroicon-o-pencil')
            ->fillForm(fn (Post $record): array => ['body' => $record->body])
            ->schema([Textarea::make('body')->required()->rows(8)])
            ->action(function (array $data, Post $record): void {
                try {
                    $changed = app(Posting::class)->editPost(Moderate::moderator(), $record, (string) $data['body']);
                } catch (PostingNotPermitted $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(Moderate::t($changed ? 'post_edited' : 'post_unchanged'))->send();
            });
    }
}
