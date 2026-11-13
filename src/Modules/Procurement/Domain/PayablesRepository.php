<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

interface PayablesRepository
{
    public function supplier(int $id): ?Supplier;

    public function insertInvoice(SupplierInvoice $invoice): int;

    /** @param list<int> $ids locks and returns invoices of one supplier keyed by id */
    public function lockInvoices(int $supplierId, array $ids): array;

    public function applyPayment(int $invoiceId, Fixed $paid, Fixed $basePaid): void;

    public function insertPayment(int $supplierId, DateTimeImmutable $date, string $currency, Fixed $rate, Fixed $amount, string $method, string $reference): int;

    public function insertAllocation(int $paymentId, int $invoiceId, Fixed $amount, Fixed $invoiceBase, Fixed $cashBase): void;

    /** Sum of base value received into stock vs invoiced, for one receipt reference. */
    public function receiptSummary(string $receiptReference): array;

    /** Records a variance clearing so the same residual is never cleared twice. */
    public function markCleared(string $receiptReference, Fixed $residual): void;
}
