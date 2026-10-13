<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Why a checked posting call refused.
 */
enum PostingDenial: string
{
    case FORUM_NOT_VISIBLE = 'forum_not_visible';
    case THREAD_NOT_ACCESSIBLE = 'thread_not_accessible';
    case NOT_ALLOWED = 'not_allowed';
    case THREAD_LOCKED = 'thread_locked';
    case THREAD_NOT_APPROVED = 'thread_not_approved';
    case AWAITING_FIRST_REVIEW = 'awaiting_first_review';

    public function message(): string
    {
        return match ($this) {
            self::FORUM_NOT_VISIBLE => 'This forum is not available to you.',
            self::THREAD_NOT_ACCESSIBLE => 'This thread is not available to you.',
            self::NOT_ALLOWED => 'You are not allowed to do that here.',
            self::THREAD_LOCKED => 'This thread is locked.',
            self::THREAD_NOT_APPROVED => 'This thread has not been approved yet.',
            self::AWAITING_FIRST_REVIEW => 'Your first post is still awaiting review.',
        };
    }
}
