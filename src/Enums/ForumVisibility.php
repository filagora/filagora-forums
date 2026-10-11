<?php

declare(strict_types=1);

namespace ForumKit\Forums\Enums;

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
}
