<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * How an announcement looks: the colour a host shows it in.
 */
enum AnnouncementSeverity: string
{
    case INFO = 'info';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Info',
            self::SUCCESS => 'Success',
            self::WARNING => 'Warning',
            self::CRITICAL => 'Critical',
        };
    }

    /**
     * The Filament colour name for badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::INFO => 'info',
            self::SUCCESS => 'success',
            self::WARNING => 'warning',
            self::CRITICAL => 'danger',
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
