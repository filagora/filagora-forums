<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Actions;

use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ThreadModeration;
use Filagora\Forums\Support\Models;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Thread moderation as Filament actions, calling `ThreadModeration`.
 */
final class ThreadActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::approve(),
            self::lock(),
            self::unlock(),
            self::pin(),
            self::unpin(),
            self::rename(),
            self::move(),
            self::delete(),
        ];
    }

    public static function approve(): Action
    {
        return self::reasoned('approveThread', 'approve', 'heroicon-o-check-circle', 'success')
            ->visible(fn (?Model $record = null): bool => $record === null || ($record instanceof Thread && ! $record->isApproved()))
            ->action(fn (array $data, array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, 'thread_approved',
                fn (Thread $t) => app(ThreadModeration::class)->approve(Moderate::moderator(), $t, $data['reason'] ?? null),
            ));
    }

    public static function lock(): Action
    {
        return self::toggle('lock', 'heroicon-o-lock-closed', false);
    }

    public static function unlock(): Action
    {
        return self::toggle('unlock', 'heroicon-o-lock-open', true);
    }

    public static function pin(): Action
    {
        return self::toggle('pin', 'heroicon-o-bookmark', false);
    }

    public static function unpin(): Action
    {
        return self::toggle('unpin', 'heroicon-o-bookmark-slash', true);
    }

    public static function rename(): Action
    {
        return Action::make('renameThread')
            ->label(Moderate::t('rename'))
            ->icon('heroicon-o-pencil')
            ->fillForm(fn (array $arguments, ?Model $record = null): array => ['title' => Moderate::record(Thread::class, $record, $arguments)?->title])
            ->schema([TextInput::make('title')->required()->maxLength(255)])
            ->action(fn (array $data, array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, 'thread_renamed',
                fn (Thread $t) => app(ThreadModeration::class)->rename(Moderate::moderator(), $t, (string) $data['title']),
            ));
    }

    public static function move(): Action
    {
        return Action::make('moveThread')
            ->label(Moderate::t('move'))
            ->icon('heroicon-o-arrows-right-left')
            ->schema([
                Select::make('forum_id')
                    ->label(Moderate::t('destination_forum'))
                    ->options(fn (): array => Models::class('forum')::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->rows(2)->maxLength(5000),
            ])
            ->action(fn (array $data, array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, 'thread_moved',
                function (Thread $t) use ($data): void {
                    $forum = Models::class('forum')::query()->findOrFail((int) $data['forum_id']);
                    assert($forum instanceof Forum);
                    app(ThreadModeration::class)->move(Moderate::moderator(), $t, $forum, $data['reason'] ?? null);
                },
            ));
    }

    public static function delete(): Action
    {
        return self::reasoned('deleteThread', 'delete_thread', 'heroicon-o-trash', 'danger')
            ->requiresConfirmation()
            ->modalDescription(Moderate::t('delete_thread_warning'))
            ->action(fn (array $data, array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, 'thread_deleted',
                fn (Thread $t) => app(ThreadModeration::class)->delete(Moderate::moderator(), $t, $data['reason'] ?? null),
            ));
    }

    private static function toggle(string $method, string $icon, bool $whenFlagged): Action
    {
        $column = str_contains($method, 'lock') ? 'is_locked' : 'is_pinned';

        return self::reasoned($method.'Thread', $method, $icon, 'gray')
            ->visible(fn (?Model $record = null): bool => $record === null || ($record instanceof Thread && (bool) $record->{$column} === $whenFlagged))
            ->action(fn (array $data, array $arguments, ?Model $record = null): bool => self::apply(
                $record, $arguments, ['lock' => 'thread_locked', 'unlock' => 'thread_unlocked', 'pin' => 'thread_pinned', 'unpin' => 'thread_unpinned'][$method],
                fn (Thread $t) => app(ThreadModeration::class)->{$method}(Moderate::moderator(), $t, $data['reason'] ?? null),
            ));
    }

    private static function reasoned(string $name, string $label, string $icon, string $color): Action
    {
        return Action::make($name)
            ->label(Moderate::t($label))
            ->icon($icon)
            ->color($color)
            ->schema([Textarea::make('reason')->label(Moderate::t('reason'))->rows(2)->maxLength(5000)]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(Thread): mixed  $call
     */
    private static function apply(?Model $record, array $arguments, string $done, callable $call): bool
    {
        $thread = Moderate::record(Thread::class, $record, $arguments);

        if (! $thread instanceof Thread) {
            Notification::make()->warning()->title(Moderate::t('gone'))->send();

            return false;
        }

        return Moderate::run(fn () => $call($thread), Moderate::t($done));
    }
}
