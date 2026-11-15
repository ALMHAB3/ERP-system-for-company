<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use Modules\Shared\Domain\Fixed;

/** One open balance held in a foreign currency, expressed as a DEBIT balance (a liability is therefore negative). */
final readonly class ForeignBalance
{
    public function __construct(
        public string $accountCode,
        public string $subject,
        public string $currency,
        public Fixed $foreign,
        /** What the ledger currently carries for it, in base currency. */
        public Fixed $carryingBase,
        /** True when the ledger account itself is held in that currency (a foreign bank account). */
        public bool $foreignAccount = false,
    ) {}
}
