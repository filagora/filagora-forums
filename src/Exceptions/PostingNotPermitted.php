<?php

declare(strict_types=1);

namespace ForumKit\Forums\Exceptions;

use ForumKit\Forums\Enums\PostingDenial;
use RuntimeException;

class PostingNotPermitted extends RuntimeException
{
    public function __construct(public readonly PostingDenial $reason)
    {
        parent::__construct($reason->message());
    }
}
