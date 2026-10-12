<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use ForumKit\Forums\Enums\PostReportStatus;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Models\PostReport;
use ForumKit\Forums\Services\PostReports;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve every open report on a post at once. On the reports table the record is a report;
 * on the queue page the action argument `id` is the post.
 */
final class ResolveReportAction
{
    public static function make(): Action
    {
        return Action::make('resolveReport')
            ->label(Moderate::t('resolve'))
            ->icon('heroicon-o-clipboard-document-check')
            ->modalWidth('lg')
            ->visible(fn (?Model $record = null): bool => $record === null || ($record instanceof PostReport && $record->status === PostReportStatus::OPEN))
            ->fillForm(['outcome' => PostReports::DISMISS])
            ->schema(fn (array $arguments, ?Model $record = null): array => [
                Radio::make('outcome')
                    ->label(Moderate::t('outcome'))
                    ->required()
                    ->options(self::options(self::post($record, $arguments)))
                    ->descriptions([
                        PostReports::DISMISS => Moderate::t('outcome_dismiss'),
                        PostReports::KEEP => Moderate::t('outcome_keep'),
                        PostReports::REMOVE => Moderate::t('outcome_remove'),
                        PostReports::REMOVE_THREAD => Moderate::t('outcome_remove_thread'),
                    ]),
                Textarea::make('note')->label(Moderate::t('note'))->rows(3)->maxLength(4000),
            ])
            ->action(function (array $data, array $arguments, ?Model $record = null): void {
                $post = self::post($record, $arguments);

                if (! $post instanceof Post) {
                    Notification::make()->warning()->title(Moderate::t('gone'))->send();

                    return;
                }

                Moderate::run(
                    fn () => app(PostReports::class)->resolve(Moderate::moderator(), $post, (string) $data['outcome'], $data['note'] ?? null),
                    Moderate::t('report_resolved'),
                );
            });
    }

    /**
     * @return array<string, string>
     */
    private static function options(?Post $post): array
    {
        $options = [
            PostReports::DISMISS => Moderate::t('dismiss'),
            PostReports::KEEP => Moderate::t('keep'),
            PostReports::REMOVE => Moderate::t('remove_post'),
        ];

        if ($post instanceof Post && app(PostReports::class)->isOnlyPost($post)) {
            $options[PostReports::REMOVE_THREAD] = Moderate::t('remove_post_and_thread');
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function post(?Model $record, array $arguments): ?Post
    {
        if ($record instanceof PostReport) {
            $post = $record->post;

            return $post instanceof Post ? $post : null;
        }

        $post = Moderate::record(Post::class, null, $arguments);

        return $post instanceof Post ? $post : null;
    }
}
