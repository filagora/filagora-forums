<?php

declare(strict_types=1);

namespace ForumKit\Forums\Exceptions;

use RuntimeException;

class ModerationNotPermitted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only moderators may do this.');
    }
}
