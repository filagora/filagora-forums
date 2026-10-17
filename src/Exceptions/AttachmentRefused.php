<?php

declare(strict_types=1);

namespace Filagora\Forums\Exceptions;

use Filagora\Forums\Enums\AttachmentRefusal;
use RuntimeException;

/**
 * An attachment that cannot be stored or served. The message is safe to show the user.
 */
final class AttachmentRefused extends RuntimeException
{
    public function __construct(public readonly AttachmentRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
