<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

/**
 * Suppresses the stats observers while a multi-step operation does its own, single stats update,
 * so the observers do not each react to the operation's intermediate saves.
 */
final class StatsSyncScope
{
    private static int $suppressPostSyncDepth = 0;

    private static int $suppressThreadSyncDepth = 0;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutPostSync(callable $callback): mixed
    {
        self::$suppressPostSyncDepth++;

        try {
            return $callback();
        } finally {
            self::$suppressPostSyncDepth = max(0, self::$suppressPostSyncDepth - 1);
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutThreadSync(callable $callback): mixed
    {
        self::$suppressThreadSyncDepth++;

        try {
            return $callback();
        } finally {
            self::$suppressThreadSyncDepth = max(0, self::$suppressThreadSyncDepth - 1);
        }
    }

    public static function suppressesPostSync(): bool
    {
        return self::$suppressPostSyncDepth > 0;
    }

    public static function suppressesThreadSync(): bool
    {
        return self::$suppressThreadSyncDepth > 0;
    }
}
