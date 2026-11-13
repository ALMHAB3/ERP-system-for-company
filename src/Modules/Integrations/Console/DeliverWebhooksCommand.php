<?php

declare(strict_types=1);

namespace Modules\Integrations\Console;

use Illuminate\Console\Command;
use Modules\Integrations\Application\Webhooks;

final class DeliverWebhooksCommand extends Command
{
    protected $signature = 'webhooks:deliver';

    protected $description = 'Send pending webhook deliveries (runs every minute from the scheduler).';

    public function handle(Webhooks $webhooks): int
    {
        $r = $webhooks->deliverDue();
        $this->info("sent {$r['sent']}, failed attempts {$r['failed']}");

        return self::SUCCESS;
    }
}
