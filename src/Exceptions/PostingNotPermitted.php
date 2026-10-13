<?php

declare(strict_types=1);

namespace Filagora\Forums\Exceptions;

use Filagora\Forums\Enums\PostingDenial;
use RuntimeException;

class PostingNotPermitted extends RuntimeException
{
    public function __construct(public readonly PostingDenial $reason)
    {
        parent::__construct($reason->message());
    }
}
