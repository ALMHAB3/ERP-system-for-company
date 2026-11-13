<?php

declare(strict_types=1);

namespace Modules\Procurement\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Procurement\Domain\PurchaseOrder;
use Modules\Procurement\Domain\PurchaseOrderLine;
use Modules\Procurement\Domain\PurchaseOrderRepository;
use Modules\Procurement\Domain\PurchaseOrderStatus;
use Modules\Shared\Domain\Fixed;

final class DbPurchaseOrderRepository implements PurchaseOrderRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(PurchaseOrder $order): PurchaseOrder
    {
        $id = $this->db->table('purchase_orders')->insertGetId([
            'number' => $order->number, 'supplier' => $order->supplier, 'currency' => $order->currency,
            'exchange_rate' => $order->exchangeRate->units, 'status' => $order->status->value,
            'required_approval_level' => $order->requiredApprovalLevel,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lines = [];
        foreach ($order->lines as $l) {
            $lineId = $this->db->table('purchase_order_lines')->insertGetId([
                'purchase_order_id' => $id, 'product_id' => $l->productId,
                'quantity' => $l->quantity->units, 'unit_price' => $l->unitPrice->units,
            ]);
            $lines[] = new PurchaseOrderLine($lineId, $l->productId, $l->quantity, $l->unitPrice);
        }

        return new PurchaseOrder($id, $order->number, $order->supplier, $order->currency, $order->exchangeRate,
            $order->status, $order->requiredApprovalLevel, $lines);
    }

    public function lock(int $id): ?PurchaseOrder
    {
        $o = $this->db->table('purchase_orders')->where('id', $id)->lockForUpdate()->first();
        if (! $o) {
            return null;
        }
        $lines = $this->db->table('purchase_order_lines')->where('purchase_order_id', $id)->orderBy('id')->get()
            ->map(static fn ($l) => new PurchaseOrderLine((int) $l->id, (int) $l->product_id, Fixed::units((int) $l->quantity), Fixed::units((int) $l->unit_price)))->all();

        return new PurchaseOrder((int) $o->id, $o->number, $o->supplier, $o->currency, Fixed::units((int) $o->exchange_rate),
            PurchaseOrderStatus::from($o->status), (int) $o->required_approval_level, $lines);
    }

    public function setStatus(int $id, PurchaseOrderStatus $status, ?int $approvedBy = null): void
    {
        $this->db->table('purchase_orders')->where('id', $id)->update(array_filter([
            'status' => $status->value, 'approved_by' => $approvedBy, 'updated_at' => now(),
        ], static fn ($v) => $v !== null));
    }
}
