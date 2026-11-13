<?php

declare(strict_types=1);

namespace Modules\Sales\Console;

use Illuminate\Console\Command;
use Modules\Sales\Application\EInvoiceService;

final class VerifyInvoicesCommand extends Command
{
    protected $signature = 'invoices:verify';

    protected $description = 'Verify the e-invoice fingerprint chain (any edited or removed invoice is reported).';

    public function handle(EInvoiceService $service): int
    {
        $r = $service->verifyChain();
        if ($r['broken'] !== []) {
            $this->error('Invoice chain BROKEN at: '.implode(', ', $r['broken']));

            return self::FAILURE;
        }
        $this->info("Invoice chain intact ({$r['checked']} invoices).");

        return self::SUCCESS;
    }
}
