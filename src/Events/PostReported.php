<?php

declare(strict_types=1);

namespace Filagora\Forums\Events;

use Filagora\Forums\Models\PostReport;
use Illuminate\Foundation\Events\Dispatchable;

final class PostReported
{
    use Dispatchable;

    public function __construct(public readonly PostReport $report) {}
}
