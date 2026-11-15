<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use DateTimeImmutable;

/** Port: anything that holds open balances in a foreign currency (payables, foreign bank accounts, ...). */
interface ForeignBalanceSource
{
    /** @return list<ForeignBalance> */
    public function openBalances(string $baseCurrency, DateTimeImmutable $asOf): array;
}
