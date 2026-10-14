<?php

declare(strict_types=1);

namespace Filagora\Forums\Exceptions;

use Filagora\Forums\Enums\PollRefusal;
use RuntimeException;

/**
 * A poll action that cannot be carried out as asked. The message is safe to show the user.
 */
final class PollRefused extends RuntimeException
{
    public function __construct(public readonly PollRefusal $reason, ?string $detail = null)
    {
        parent::__construct($detail ?? $reason->message());
    }
}
