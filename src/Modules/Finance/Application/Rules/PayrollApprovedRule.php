<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Hr\Domain\Events\PayrollApproved;
use Modules\Shared\Domain\DomainEvent;

/** Dr Salaries expense + Employer social expense / Cr Salaries payable (net) + Social security payable + Employee advances (repaid). */
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
        $lines = [JournalLineData::debit($a['payroll_expense'], $event->expense, "payroll {$event->period}")];
        if ($event->employerSocial->isPositive()) {
            $lines[] = JournalLineData::debit($a['employer_social_expense'], $event->employerSocial);
        }
        $lines[] = JournalLineData::credit($a['salaries_payable'], $event->net);
        if ($event->social->isPositive()) {
            $lines[] = JournalLineData::credit($a['social_payable'], $event->social);
        }
        if ($event->advances->isPositive()) {
            $lines[] = JournalLineData::credit($a['employee_advances'], $event->advances, 'advance repayments');
        }

        return new JournalEntryData("Payroll {$event->period}", $lines, 'payroll_run', (string) $event->runId, 'payroll_approved');
    }
}
