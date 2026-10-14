<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Whether a poll takes votes. Every value ever stored is kept, so existing data loads.
 */
enum PollStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Closed',
        };
    }
}
