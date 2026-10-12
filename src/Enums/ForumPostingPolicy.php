<?php

declare(strict_types=1);

namespace ForumKit\Forums\Enums;

/**
 * Who can post in a forum. Every value ever stored is kept, so existing data loads.
 */
enum ForumPostingPolicy: string
{
    case PUBLIC = 'public';
    case REGISTERED = 'registered';
    case STAFF_ONLY = 'staff_only';
    case STAFF_AUTHOR_ONLY = 'staff_author_only';
    case ENTITLEMENT_ONLY = 'entitlement_only';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIC => 'Anyone',
            self::REGISTERED => 'Registered members',
            self::STAFF_ONLY => 'Staff only',
            self::STAFF_AUTHOR_ONLY => 'Staff, and authors replying to their own threads',
            self::ENTITLEMENT_ONLY => 'Entitlement holders',
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
