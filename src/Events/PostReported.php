<?php

declare(strict_types=1);

namespace ForumKit\Forums\Events;

use ForumKit\Forums\Models\PostReport;
use Illuminate\Foundation\Events\Dispatchable;

final class PostReported
{
    use Dispatchable;

    public function __construct(public readonly PostReport $report) {}
}
