<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Hr\Domain\Events\PayrollPaid;
use Modules\Shared\Domain\DomainEvent;

/** Dr Salaries payable / Cr Cash or bank. */
final class PayrollPaidRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof PayrollPaid;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var PayrollPaid $event */
        // Each branch's salaries payable is cleared on its own line; the money leaves the till or bank it was paid from.
        $lines = [];
        foreach ($event->netByBranch !== [] ? $event->netByBranch : [0 => $event->amount] as $branch => $net) {
            if ($net->isPositive()) {
                $lines[] = JournalLineData::debit(config('erp.accounts.salaries_payable'), $net, "payroll {$event->period}", null, null, $branch === 0 ? null : $branch);
            }
        }
        $lines[] = JournalLineData::credit($event->cashAccount, $event->amount);

        return new JournalEntryData("Salaries paid {$event->period}", $lines, 'payroll_run', (string) $event->runId, 'payroll_paid');
    }
}
