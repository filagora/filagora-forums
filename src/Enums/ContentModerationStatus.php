<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

enum ContentModerationStatus: string
{
    case APPROVED = 'approved';
    case PENDING = 'pending';
    case REJECTED = 'rejected';
    case REJECTED_FINAL = 'rejected_final';

    public function label(): string
    {
        return match ($this) {
            self::APPROVED => 'Approved',
            self::PENDING => 'Pending review',
            self::REJECTED => 'Rejected',
            self::REJECTED_FINAL => 'Rejected Permanently',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::APPROVED => 'success',
            self::PENDING => 'warning',
            self::REJECTED, self::REJECTED_FINAL => 'danger',
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
