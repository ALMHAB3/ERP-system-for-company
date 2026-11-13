<?php

declare(strict_types=1);

namespace Modules\Procurement\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Procurement\Domain\PayablesRepository;
use Modules\Procurement\Domain\Supplier;
use Modules\Procurement\Domain\SupplierInvoice;
use Modules\Shared\Domain\Fixed;

final class DbPayablesRepository implements PayablesRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function supplier(int $id): ?Supplier
    {
        $s = $this->db->table('suppliers')->where('id', $id)->where('active', true)->first();

        return $s ? new Supplier((int) $s->id, $s->name, $s->currency, (int) $s->terms_days) : null;
    }

    public function insertInvoice(SupplierInvoice $i): int
    {
        return $this->db->table('supplier_invoices')->insertGetId([
            'supplier_id' => $i->supplierId, 'number' => $i->number,
            'invoice_date' => $i->invoiceDate->format('Y-m-d'), 'due_date' => $i->dueDate->format('Y-m-d'),
            'currency' => $i->currency, 'exchange_rate' => $i->exchangeRate->units,
            'subtotal' => $i->subtotal->units, 'tax' => $i->tax->units, 'base_total' => $i->baseTotal()->units,
            'kind' => $i->kind->value, 'receipt_reference' => $i->receiptReference, 'expense_account' => $i->expenseAccount,
            'paid' => 0, 'base_paid' => 0, 'created_at' => now(),
        ]);
    }

    public function lockInvoices(int $supplierId, array $ids): array
    {
        $out = [];
        foreach ($this->db->table('supplier_invoices')->where('supplier_id', $supplierId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $r) {
            $out[(int) $r->id] = new SupplierInvoice(
                (int) $r->id, (int) $r->supplier_id, $r->number, new DateTimeImmutable($r->invoice_date), new DateTimeImmutable($r->due_date),
                $r->currency, Fixed::units((int) $r->exchange_rate), Fixed::units((int) $r->subtotal), Fixed::units((int) $r->tax),
                InvoiceKind::from($r->kind), $r->receipt_reference, $r->expense_account, Fixed::units((int) $r->paid), Fixed::units((int) $r->base_paid),
            );
        }

        return $out;
    }

    public function applyPayment(int $invoiceId, Fixed $paid, Fixed $basePaid): void
    {
        $this->db->table('supplier_invoices')->where('id', $invoiceId)->update(['paid' => $paid->units, 'base_paid' => $basePaid->units]);
    }

    public function insertPayment(int $supplierId, DateTimeImmutable $date, string $currency, Fixed $rate, Fixed $amount, string $method, string $reference, ?string $cashAccount = null): int
    {
        return $this->db->table('supplier_payments')->insertGetId([
            'supplier_id' => $supplierId, 'paid_on' => $date->format('Y-m-d'), 'currency' => $currency,
            'exchange_rate' => $rate->units, 'amount' => $amount->units, 'method' => $method, 'reference' => $reference, 'cash_account' => $cashAccount, 'created_at' => now(),
        ]);
    }

    public function insertAllocation(int $paymentId, int $invoiceId, Fixed $amount, Fixed $invoiceBase, Fixed $cashBase): void
    {
        $this->db->table('supplier_payment_allocations')->insert([
            'supplier_payment_id' => $paymentId, 'supplier_invoice_id' => $invoiceId, 'amount' => $amount->units,
            'invoice_base' => $invoiceBase->units, 'cash_base' => $cashBase->units,
        ]);
    }

    public function receiptSummary(string $receiptReference): array
    {
        $receipts = (int) $this->db->table('stock_moves')->where('reference', $receiptReference)->where('type', 'receipt')->sum('total_cost');
        $invoiced = 0;
        foreach ($this->db->table('supplier_invoices')->where('receipt_reference', $receiptReference)->get(['subtotal', 'exchange_rate']) as $r) {
            $invoiced += Fixed::units((int) $r->subtotal)->mul(Fixed::units((int) $r->exchange_rate))->units;
        }
        $cleared = (int) $this->db->table('grir_clearings')->where('receipt_reference', $receiptReference)->sum('residual');
        $open = $receipts - $invoiced - $cleared;

        return ['receipts' => (string) Fixed::units($receipts), 'invoiced' => (string) Fixed::units($invoiced), 'cleared' => (string) Fixed::units($cleared), 'open' => (string) Fixed::units($open)];
    }

    public function markCleared(string $receiptReference, Fixed $residual): void
    {
        $this->db->table('grir_clearings')->insert(['receipt_reference' => $receiptReference, 'residual' => $residual->units, 'created_at' => now()]);
    }
}
