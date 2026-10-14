<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Who an announcement is for, by whether they are signed in.
 */
enum AnnouncementAudience: string
{
    case EVERYONE = 'everyone';
    case GUESTS_ONLY = 'guests_only';
    case AUTHENTICATED_ONLY = 'authenticated_only';

    public function label(): string
    {
        return match ($this) {
            self::EVERYONE => 'Everyone',
            self::GUESTS_ONLY => 'Guests only',
            self::AUTHENTICATED_ONLY => 'Signed-in users only',
        };
    }

    public function matches(bool $isAuthenticated): bool
    {
        return match ($this) {
            self::EVERYONE => true,
            self::AUTHENTICATED_ONLY => $isAuthenticated,
            self::GUESTS_ONLY => ! $isAuthenticated,
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
