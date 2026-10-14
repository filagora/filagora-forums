<?php

declare(strict_types=1);

namespace Filagora\Forums\Enums;

/**
 * Why a poll action was refused.
 */
enum PollRefusal: string
{
    case ALREADY_HAS_POLL = 'already_has_poll';
    case HAS_VOTES = 'has_votes';
    case CLOSED = 'closed';
    case ALREADY_VOTED = 'already_voted';
    case NOT_VISIBLE = 'not_visible';
    case INVALID_ANSWERS = 'invalid_answers';
    case INVALID_QUESTIONS = 'invalid_questions';
    case WRONG_STATE = 'wrong_state';

    public function message(): string
    {
        return __("filagora-forums::polls.refusals.{$this->value}");
    }
}
