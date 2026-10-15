<?php

declare(strict_types=1);

namespace Filagora\Forums\Console;

use Filagora\Forums\Enums\DeliveryMode;
use Filagora\Forums\Services\Subscriptions;
use Illuminate\Console\Command;

/**
 * Sends the daily or weekly thread digests that are due.
 */
final class SendDigestCommand extends Command
{
    protected $signature = 'filagora:forums:send-digest {frequency : daily or weekly} {--dry-run : Report counts without sending}';

    protected $description = 'Send the daily or weekly digests of new posts in followed threads.';

    public function handle(Subscriptions $subscriptions): int
    {
        $frequency = DeliveryMode::tryFrom(strtolower(trim((string) $this->argument('frequency'))));

        if ($frequency === null || ! $frequency->isDigest()) {
            $this->error('Frequency must be "daily" or "weekly".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sent = $subscriptions->sendDigests($frequency, $dryRun);

        $this->info(sprintf('Digest %s: users=%d, items=%d, frequency=%s', $dryRun ? 'dry-run' : 'sent', $sent['users'], $sent['items'], $frequency->value));

        return self::SUCCESS;
    }
}
