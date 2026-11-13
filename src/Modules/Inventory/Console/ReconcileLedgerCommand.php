<?php

declare(strict_types=1);

namespace Modules\Inventory\Console;

use Illuminate\Console\Command;
use Modules\Inventory\Application\LedgerReconciler;

final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Verify stored stock balances against the immutable inventory ledger (schedule nightly).';

    public function handle(LedgerReconciler $reconciler): int
    {
        $drifts = $reconciler->reconcile();
        if ($drifts === []) {
            $this->info('Inventory ledger reconciled: no drift.');

            return self::SUCCESS;
        }
        foreach ($drifts as $drift) {
            $this->error((string) $drift);
        }

        return self::FAILURE;
    }
}
