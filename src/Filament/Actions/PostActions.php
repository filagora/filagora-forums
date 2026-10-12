<?php

declare(strict_types=1);

namespace ForumKit\Forums\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Models\Post;
use ForumKit\Forums\Services\PostModeration;
use Illuminate\Database\Eloquent\Model;

/**
 * Post moderation as Filament actions, calling `PostModeration`.
 */
final class PostActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::approve(),
            self::unapprove(),
            self::reject(),
            self::delete(),
            self::restore(),
        ];
    }

    public static function approve(): Action
    {
        return self::make('approvePost', 'approve', 'heroicon-o-check-circle', 'success', 'post_approved', 'approve')
            ->visible(fn (?Model $record = null): bool => $record === null || (self::post($record)?->moderation_status !== ContentModerationStatus::APPROVED));
    }

    public static function unapprove(): Action
    {
        return self::make('unapprovePost', 'unapprove', 'heroicon-o-arrow-uturn-left', 'gray', 'post_unapproved', 'unapprove')
            ->visible(fn (?Model $record = null): bool => self::post($record)?->moderation_status === ContentModerationStatus::APPROVED);
    }

    public static function reject(): Action
    {
        return self::make('rejectPost', 'reject', 'heroicon-o-x-circle', 'danger', 'post_rejected', 'reject')
            ->visible(fn (?Model $record = null): bool => $record === null || ! in_array(self::post($record)?->moderation_status, [ContentModerationStatus::REJECTED, ContentModerationStatus::REJECTED_FINAL], true));
    }

    public static function delete(): Action
    {
        return self::make('deletePost', 'delete_post', 'heroicon-o-trash', 'danger', 'post_deleted', 'delete')
            ->requiresConfirmation()
            ->visible(fn (?Model $record = null): bool => $record === null || (self::post($record)?->is_deleted === false));
    }

    public static function restore(): Action
    {
        return Action::make('restorePost')
            ->label(Moderate::t('restore'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (?Model $record = null): bool => self::post($record)?->is_deleted === true)
            ->action(fn (array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, 'post_restored',
                fn (Post $p) => app(PostModeration::class)->restore(Moderate::moderator(), $p),
            ));
    }

    private static function make(string $name, string $label, string $icon, string $color, string $done, string $method): Action
    {
        return Action::make($name)
            ->label(Moderate::t($label))
            ->icon($icon)
            ->color($color)
            ->schema([Textarea::make('reason')->label(Moderate::t('reason'))->rows(2)->maxLength(5000)])
            ->action(fn (array $data, array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, $done,
                fn (Post $p) => app(PostModeration::class)->{$method}(Moderate::moderator(), $p, $data['reason'] ?? null),
            ));
    }

    private static function post(?Model $record): ?Post
    {
        return $record instanceof Post ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(Post): mixed  $call
     */
    private static function apply(?Model $record, array $arguments, string $done, callable $call): bool
    {
        $post = Moderate::record(Post::class, $record, $arguments);

        if (! $post instanceof Post) {
            Notification::make()->warning()->title(Moderate::t('gone'))->send();

            return false;
        }

        return Moderate::run(fn () => $call($post), Moderate::t($done));
    }
}
