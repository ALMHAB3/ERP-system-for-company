<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Hr\Domain\Events\PayrollApproved;
use Modules\Shared\Domain\DomainEvent;

/** Dr Salaries expense / Cr Salaries payable (net) + Employee advances (repaid). */
final class PayrollApprovedRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof PayrollApproved;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var PayrollApproved $event */
        $a = config('erp.accounts');
        // One set of lines per branch, so each branch's profit and loss carries its own salaries.
        $parts = $event->byBranch !== [] ? $event->byBranch : [0 => ['expense' => $event->expense, 'net' => $event->net, 'advances' => $event->advances]];
        $lines = [];
        foreach ($parts as $branch => $p) {
            $b = $branch === 0 ? null : $branch;
            $lines[] = JournalLineData::debit($a['payroll_expense'], $p['expense'], "payroll {$event->period}", null, null, $b);
            $lines[] = JournalLineData::credit($a['salaries_payable'], $p['net'], null, null, null, $b);
            if ($p['advances']->isPositive()) {
                $lines[] = JournalLineData::credit($a['employee_advances'], $p['advances'], 'advance repayments', null, null, $b);
            }
        }
        $lines = array_values(array_filter($lines, static fn (JournalLineData $l) => $l->debit->isPositive() || $l->credit->isPositive()));

        return new JournalEntryData("Payroll {$event->period}", $lines, 'payroll_run', (string) $event->runId, 'payroll_approved');
    }
}
