<?php

declare(strict_types=1);

namespace ForumKit\Forums\Exceptions;

use RuntimeException;

/**
 * A moderation request that cannot be carried out as asked (a no-op move, an empty or oversized
 * batch, a destination that does not exist). The message is safe to show a moderator.
 */
class InvalidModeration extends RuntimeException {}
