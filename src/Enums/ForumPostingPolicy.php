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
}
