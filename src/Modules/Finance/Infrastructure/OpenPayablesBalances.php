<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Finance\Domain\ForeignBalance;
use Modules\Finance\Domain\ForeignBalanceSource;
use Modules\Shared\Domain\Fixed;

/** Unpaid supplier invoices in a foreign currency: what is still owed, and the base value it was booked at. */
final class OpenPayablesBalances implements ForeignBalanceSource
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function openBalances(string $baseCurrency, DateTimeImmutable $asOf): array
    {
        $account = (string) config('erp.accounts.payables');
        $rows = $this->db->table('supplier_invoices as i')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where('i.currency', '!=', $baseCurrency)->where('i.invoice_date', '<=', $asOf->format('Y-m-d'))
            ->whereRaw('i.subtotal + i.tax - i.paid > 0')->orderBy('i.id')
            ->get(['i.number', 'i.currency', 'i.subtotal', 'i.tax', 'i.paid', 'i.base_total', 'i.base_paid', 's.name']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = new ForeignBalance(
                $account, "{$r->name} / {$r->number}", $r->currency,
                Fixed::units(-((int) $r->subtotal + (int) $r->tax - (int) $r->paid)),
                Fixed::units(-((int) $r->base_total - (int) $r->base_paid)),
            );
        }

        return $out;
    }
}
