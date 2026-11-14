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
        return new JournalEntryData("Salaries paid {$event->period}", [
            JournalLineData::debit(config('erp.accounts.salaries_payable'), $event->amount, "payroll {$event->period}"),
            JournalLineData::credit($event->cashAccount, $event->amount),
        ], 'payroll_run', (string) $event->runId, 'payroll_paid');
    }
}
