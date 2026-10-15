<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Why a reaction was refused.
 */
enum ReactionRefusal: string
{
    case UNKNOWN_TYPE = 'unknown_type';
    case NOT_VISIBLE = 'not_visible';
    case POST_DELETED = 'post_deleted';
    case POST_NOT_APPROVED = 'post_not_approved';

    public function message(): string
    {
        return __("filagora-forums::reactions.refusals.{$this->value}");
    }
}
