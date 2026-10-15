<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * How a person wants to hear about new posts in the threads they follow.
 */
enum DeliveryMode: string
{
    case INSTANT = 'instant';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case OFF = 'off';

    public function isDigest(): bool
    {
        return $this === self::DAILY || $this === self::WEEKLY;
    }
}
