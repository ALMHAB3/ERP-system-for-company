<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use Modules\Shared\Domain\Fixed;

interface SettlementRepository
{
    /** Shipment status by receipt reference ('shipment:REF'), null if unknown. */
    public function shipmentStatus(string $receiptReference): ?string;

    public function isSettled(string $receiptReference): bool;

    /** @return list<array{id:int,number:string,subtotal:Fixed,tax:Fixed,paid:Fixed}> invoices that settle this receipt */
    public function linkedInvoices(string $receiptReference): array;

    /** @return list<array{cash_base:Fixed,invoice_base:Fixed}> payment allocations of one invoice */
    public function allocations(int $invoiceId): array;

    /** @return list<array{product_id:int,warehouse_id:int,lot_no:string,quantity:Fixed,total_cost:Fixed}> */
    public function receiptLines(string $receiptReference): array;

    /** @return list<array{warehouse_id:int,on_hand:Fixed}> where this lot still has stock */
    public function lotHoldings(int $productId, string $lotNo): array;

    /** @param list<array<string,mixed>> $lines */
    public function insertSettlement(string $receiptReference, Fixed $fx, Fixed $cleared, Fixed $delta, Fixed $inventoryPart, Fixed $cogsPart, ?int $userId, array $lines): int;
}
