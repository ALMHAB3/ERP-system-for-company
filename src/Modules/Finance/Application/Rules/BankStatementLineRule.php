<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\Events\BankStatementLineBooked;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Shared\Domain\DomainEvent;

/** Money in: Dr Bank / Cr counter account. Money out (charges, direct debits): Dr counter account / Cr Bank. */
final class BankStatementLineRule implements PostingRule
{
    public const RULE = 'bank_statement_line';

    public function handles(DomainEvent $event): bool
    {
        return $event instanceof BankStatementLineBooked;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var BankStatementLineBooked $event */
        $foreign = $event->currency !== null ? $event->amount->abs() : null;
        $lines = $event->amount->isPositive()
            ? [
                JournalLineData::debit($event->bankAccountCode, $event->baseAmount, $event->description, $event->currency, $foreign),
                JournalLineData::credit($event->counterAccountCode, $event->baseAmount, $event->description),
            ]
            : [
                JournalLineData::debit($event->counterAccountCode, $event->baseAmount, $event->description),
                JournalLineData::credit($event->bankAccountCode, $event->baseAmount, $event->description, $event->currency, $foreign),
            ];

        return new JournalEntryData("Bank statement: {$event->description}", $lines, 'bank_statement_line', (string) $event->lineId, self::RULE, $event->date);
    }
}
