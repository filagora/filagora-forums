<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Actions;

use Closure;
use Filagora\Forums\Exceptions\InvalidModeration;
use Filagora\Forums\Exceptions\ModerationNotPermitted;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Plumbing shared by the moderation actions, which run on a table row (Filament hands the
 * record over) or on the queue page (the record's id comes in the action arguments).
 */
final class Moderate
{
    public static function moderator(): Authenticatable
    {
        $user = auth()->user();
        abort_unless($user instanceof Authenticatable, 403);

        return $user;
    }

    /**
     * Run a moderation service call, showing a refusal as a notification instead of an error.
     * Returns whether it went through.
     *
     * @param  Closure(): mixed  $call
     */
    public static function run(Closure $call, string $successTitle): bool
    {
        try {
            $call();
        } catch (InvalidModeration $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        } catch (ModerationNotPermitted $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }

        Notification::make()->success()->title($successTitle)->send();

        return true;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $arguments
     * @return TModel|null
     */
    public static function record(string $model, ?Model $record, array $arguments): ?Model
    {
        if ($record instanceof $model) {
            return $record;
        }

        return $model::query()->find((int) ($arguments['id'] ?? 0));
    }

    public static function t(string $key): string
    {
        return __("filagora-forums::messages.actions.{$key}");
    }
}
