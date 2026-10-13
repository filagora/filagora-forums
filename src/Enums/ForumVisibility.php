<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Who can see a forum. Every value ever stored is kept, so existing data loads.
 */
enum ForumVisibility: string
{
    case PUBLIC = 'public';
    case REGISTERED = 'registered';
    case STAFF_ONLY = 'staff_only';
    case ENTITLEMENT_ONLY = 'entitlement_only';
    case PAID_ONLY = 'paid_only';
    case PAID_OR_AUTHOR = 'paid_or_author';
    case STAFF_AUTHOR_ONLY = 'staff_author_only';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIC => 'Public',
            self::REGISTERED => 'Registered members',
            self::STAFF_ONLY => 'Staff only',
            self::ENTITLEMENT_ONLY => 'Entitlement holders',
            self::PAID_ONLY => 'Paid members',
            self::PAID_OR_AUTHOR => 'Paid members or authors',
            self::STAFF_AUTHOR_ONLY => 'Staff, and authors of their own threads',
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
