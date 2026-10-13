<?php

declare(strict_types=1);

namespace Filagora\Forums\Filament\Concerns;

use Illuminate\Http\Request;

/**
 * Counts for navigation badges, worked out once per HTTP request however often asked.
 */
trait RequestMetric
{
    /**
     * @param  callable(): int  $resolver
     */
    protected static function rememberRequestMetric(string $name, callable $resolver): int
    {
        $request = app()->bound('request') ? app(Request::class) : null;
        $key = "filagora.forums.metric.{$name}";

        if ($request instanceof Request && $request->attributes->has($key)) {
            return (int) $request->attributes->get($key);
        }

        $value = (int) $resolver();
        $request?->attributes->set($key, $value);

        return $value;
    }

    protected static function badgeCount(int $count): ?string
    {
        return $count > 0 ? ($count > 99 ? '99+' : (string) $count) : null;
    }
}
