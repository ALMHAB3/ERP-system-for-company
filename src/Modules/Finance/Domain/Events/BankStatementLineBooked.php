<?php

declare(strict_types=1);

namespace Modules\Finance\Domain\Events;

use DateTimeImmutable;
use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** A statement line with no ledger counterpart (bank charge, interest, direct debit) was booked from the statement. */
final readonly class BankStatementLineBooked implements DomainEvent
{
    public function __construct(
        public int $lineId,
        public string $bankAccountCode,
        public string $counterAccountCode,
        /** Statement amount in the bank's currency: + money in, - money out. */
        public Fixed $amount,
        /** The same amount in the base currency (always positive). */
        public Fixed $baseAmount,
        /** Set when the bank account is not in the base currency. */
        public ?string $currency,
        public string $description,
        public DateTimeImmutable $date,
    ) {}
}
