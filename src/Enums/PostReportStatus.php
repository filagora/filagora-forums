<?php

declare(strict_types=1);

namespace ForumKit\Forums\Enums;

enum PostReportStatus: string
{
    case OPEN = 'open';
    case REVIEWED = 'reviewed';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::REVIEWED => 'Reviewed',
            self::DISMISSED => 'Dismissed',
        };
    }

    /**
     * @return array<string, string> value => label, for selects and filters.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
