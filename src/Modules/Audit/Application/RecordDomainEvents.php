<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Modules\Import\Domain\Events\LandedCostFinalized;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\Events\StockReserved;
use Modules\Sales\Domain\Events\InvoiceIssued;
use Modules\Shared\Domain\DomainEvent;

/** Observer: every operational event lands in the audit trail inside its own transaction. */
final class RecordDomainEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DomainEvent $event): void
    {
        match (true) {
            $event instanceof StockMoved => $this->audit->record('stock.'.$event->move->type->value, 'stock_move', $event->move->id, [
                'product_id' => $event->move->productId, 'warehouse_id' => $event->move->warehouseId,
                'quantity' => $event->move->quantity, 'total_cost' => $event->move->totalCost, 'reference' => $event->move->reference,
            ]),
            $event instanceof StockReserved => $this->audit->record('stock.reserved', 'stock_reservation', $event->reservation->id, [
                'product_id' => $event->reservation->productId, 'warehouse_id' => $event->reservation->warehouseId,
                'quantity' => $event->reservation->quantity, 'reference' => $event->reservation->reference,
            ]),
            $event instanceof LandedCostFinalized => $this->audit->record('shipment.costed', 'shipment', $event->shipmentId, [
                'total' => $event->result->total,
            ]),
            $event instanceof InvoiceIssued => $this->audit->record('sales.invoice_issued', 'sales_invoice', $event->invoiceId, [
                'number' => $event->number, 'order_id' => $event->orderId, 'net' => $event->net, 'tax' => $event->tax, 'gross' => $event->gross,
            ]),
            default => null,
        };
    }
}
