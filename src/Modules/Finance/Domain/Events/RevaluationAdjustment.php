<?php

declare(strict_types=1);

namespace Modules\Finance\Domain\Events;

use Modules\Shared\Domain\Fixed;

final readonly class RevaluationAdjustment
{
    public function __construct(
        public string $accountCode,
        /** Change to the account's debit balance in base currency: positive = debit the account, negative = credit it. */
        public Fixed $difference,
        public string $memo,
        /** Currency of the balance being revalued. */
        public string $currency,
        /** True for accounts held in that currency (bank accounts): the ledger line is tagged with a zero foreign amount. */
        public bool $foreignAccount = false,
    ) {}
}
