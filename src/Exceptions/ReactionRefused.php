<?php

declare(strict_types=1);

namespace Filagora\Forums\Exceptions;

use Filagora\Forums\Enums\ReactionRefusal;
use RuntimeException;

/**
 * A reaction that cannot be made. The message is safe to show the user.
 */
final class ReactionRefused extends RuntimeException
{
    public function __construct(public readonly ReactionRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
