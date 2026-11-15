<?php

declare(strict_types=1);

namespace Modules\Procurement\Application;

use DateTimeImmutable;
use DomainException;
use Modules\Procurement\Domain\Events\GoodsReceivedClearedToVariance;
use Modules\Procurement\Domain\Events\SupplierInvoiceRecorded;
use Modules\Procurement\Domain\Events\SupplierPaid;
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Procurement\Domain\PayablesRepository;
use Modules\Procurement\Domain\SupplierInvoice;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/** Supplier invoices (goods, freight, customs, clearance, expenses), payments with FX difference, GR/IR clearing. */
final class PayablesService
{
    public function __construct(
        private readonly PayablesRepository $repo,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    public function recordInvoice(
        int $supplierId, string $number, DateTimeImmutable $invoiceDate, string $currency, Fixed $rate,
        Fixed $subtotal, Fixed $tax, InvoiceKind $kind, ?string $receiptReference = null, ?string $expenseAccount = null,
        ?DateTimeImmutable $dueDate = null,
    ): int {
        if (! $subtotal->isPositive() || $tax->isNegative() || ! $rate->isPositive()) {
            throw new DomainException('Invoice amounts must be positive and the exchange rate greater than zero.');
        }
        if ($kind === InvoiceKind::Expense && $expenseAccount === null) {
            throw new DomainException('An expense invoice needs the expense account to debit.');
        }
        if ($kind->clearsGoodsReceived() && $receiptReference === null) {
            throw new DomainException('Goods, freight, customs and clearance invoices must reference the shipment or purchase order they settle.');
        }

        return $this->tx->run(function () use ($supplierId, $number, $invoiceDate, $currency, $rate, $subtotal, $tax, $kind, $receiptReference, $expenseAccount, $dueDate): int {
            $supplier = $this->repo->supplier($supplierId) ?? throw new DomainException("Supplier {$supplierId} not found.");
            $invoice = new SupplierInvoice(
                null, $supplierId, $number, $invoiceDate, $dueDate ?? $invoiceDate->modify("+{$supplier->termsDays} days"),
                strtoupper($currency), $rate, $subtotal, $tax, $kind, $receiptReference, $expenseAccount,
            );
            $id = $this->repo->insertInvoice($invoice);
            $this->events->dispatch(new SupplierInvoiceRecorded($id, $invoice, $supplier->name));

            return $id;
        });
    }

    /**
     * Pays one supplier. Every payment is allocated to specific invoices (no unallocated advances in this version).
     * Allocations are in the invoices' currency; $rate is today's rate used to value the cash leaving.
     *
     * @param  array<int,Fixed>  $allocations  invoice id => amount in invoice currency
     */
    public function pay(int $supplierId, DateTimeImmutable $date, string $currency, Fixed $rate, string $method, string $reference, array $allocations, ?string $cashAccount = null): int
    {
        if ($allocations === [] || ! $rate->isPositive()) {
            throw new DomainException('A payment needs allocations and a positive exchange rate.');
        }

        return $this->tx->run(function () use ($supplierId, $date, $currency, $rate, $method, $reference, $allocations, $cashAccount): int {
            $supplier = $this->repo->supplier($supplierId) ?? throw new DomainException("Supplier {$supplierId} not found.");
            $invoices = $this->repo->lockInvoices($supplierId, array_keys($allocations));

            $amount = Fixed::zero();
            $payableTotal = Fixed::zero();
            $cashTotal = Fixed::zero();
            $rows = [];
            foreach ($allocations as $invoiceId => $alloc) {
                $inv = $invoices[$invoiceId] ?? throw new DomainException("Invoice {$invoiceId} does not belong to this supplier.");
                if ($inv->currency !== strtoupper($currency)) {
                    throw new DomainException("Invoice {$inv->number} is in {$inv->currency}; pay it in the same currency.");
                }
                if (! $alloc->isPositive() || $alloc->gt($inv->balance())) {
                    throw new DomainException("Allocation to invoice {$inv->number} must be positive and not exceed its balance {$inv->balance()}.");
                }
                // Closing an invoice takes the exact remaining base value, so no rounding residue stays in AP.
                $invoiceBase = $alloc->equals($inv->balance()) ? $inv->baseBalance() : $alloc->mul($inv->exchangeRate);
                $cashBase = $alloc->mul($rate);

                $amount = $amount->add($alloc);
                $payableTotal = $payableTotal->add($invoiceBase);
                $cashTotal = $cashTotal->add($cashBase);
                $rows[] = [$invoiceId, $alloc, $invoiceBase, $cashBase, $inv];
            }

            $paymentId = $this->repo->insertPayment($supplierId, $date, strtoupper($currency), $rate, $amount, $method, $reference, $cashAccount);
            foreach ($rows as [$invoiceId, $alloc, $invoiceBase, $cashBase, $inv]) {
                $this->repo->insertAllocation($paymentId, $invoiceId, $alloc, $invoiceBase, $cashBase);
                $this->repo->applyPayment($invoiceId, ($inv->paid ?? Fixed::zero())->add($alloc), ($inv->basePaid ?? Fixed::zero())->add($invoiceBase));
            }
            $this->events->dispatch(new SupplierPaid($paymentId, $supplier->name, $payableTotal, $cashTotal, $reference, $cashAccount, strtoupper($currency), $amount));

            return $paymentId;
        });
    }

    /**
     * Goods Received / Invoice Received for one shipment or PO: what stock was valued at vs what suppliers billed.
     *
     * @return array{receipts: string, invoiced: string, open: string}
     */
    public function receiptStatus(string $receiptReference): array
    {
        return $this->repo->receiptSummary($receiptReference);
    }

    /** Moves the unmatched remainder of a fully invoiced receipt to price variance. Requires an explicit reason. */
    public function clearToVariance(string $receiptReference, string $reason): Fixed
    {
        return $this->tx->run(function () use ($receiptReference, $reason): Fixed {
            $summary = $this->repo->receiptSummary($receiptReference);
            $residual = Fixed::parse($summary['open']);
            if ($residual->isZero()) {
                throw new DomainException("{$receiptReference} has nothing to clear.");
            }
            $this->events->dispatch(new GoodsReceivedClearedToVariance($receiptReference, $residual, $reason));
            $this->repo->markCleared($receiptReference, $residual);

            return $residual;
        });
    }
}
