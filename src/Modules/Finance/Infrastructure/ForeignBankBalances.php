<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Finance\Domain\ForeignBalance;
use Modules\Finance\Domain\ForeignBalanceSource;
use Modules\Shared\Domain\Fixed;

/** Bank accounts held in a foreign currency: currency on hand vs the base value the ledger carries. */
final class ForeignBankBalances implements ForeignBalanceSource
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function openBalances(string $baseCurrency, DateTimeImmutable $asOf): array
    {
        $out = [];
        foreach ($this->db->table('bank_accounts')->where('currency', '!=', $baseCurrency)->orderBy('id')->get() as $bank) {
            $sum = $this->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.account_code', $bank->gl_account)->where('e.posted_at', '<=', $asOf->format('Y-m-d').' 23:59:59')
                ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as base, COALESCE(SUM(CASE WHEN l.currency = ? THEN l.amount_currency ELSE 0 END), 0) as foreign_amount', [$bank->currency])
                ->first();
            if ((int) $sum->foreign_amount === 0 && (int) $sum->base === 0) {
                continue;
            }
            $out[] = new ForeignBalance($bank->gl_account, $bank->name, $bank->currency, Fixed::units((int) $sum->foreign_amount), Fixed::units((int) $sum->base), true);
        }

        return $out;
    }
}
