<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\SalesOrder;
use Modules\Sales\Domain\SalesOrderLine;
use Modules\Sales\Domain\SalesOrderRepository;
use Modules\Shared\Domain\Fixed;

final class DbSalesOrderRepository implements SalesOrderRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(SalesOrder $order): SalesOrder
    {
        $id = $this->db->table('sales_orders')->insertGetId([
            'number' => 'PENDING-'.bin2hex(random_bytes(6)), 'customer_id' => $order->customerId,
            'warehouse_id' => $order->warehouseId, 'status' => $order->status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $number = 'SO-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->db->table('sales_orders')->where('id', $id)->update(['number' => $number]);

        $lines = [];
        foreach ($order->lines as $l) {
            $lineId = $this->db->table('sales_order_lines')->insertGetId([
                'sales_order_id' => $id, 'product_id' => $l->productId, 'quantity' => $l->quantity->units,
                'list_price' => $l->listPrice->units, 'discount_rate' => $l->discountRate->units,
                'unit_price' => $l->unitPrice->units, 'reservation_id' => $l->reservationId,
            ]);
            $lines[] = new SalesOrderLine($lineId, $l->productId, $l->quantity, $l->listPrice, $l->discountRate, $l->unitPrice, $l->reservationId);
        }

        return new SalesOrder($id, $number, $order->customerId, $order->warehouseId, $order->status, $lines);
    }

    public function lock(int $id): ?SalesOrder
    {
        return $this->load($id, true);
    }

    public function find(int $id): ?SalesOrder
    {
        return $this->load($id, false);
    }

    private function load(int $id, bool $lock): ?SalesOrder
    {
        $query = $this->db->table('sales_orders')->where('id', $id);
        $o = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $o) {
            return null;
        }
        $lines = $this->db->table('sales_order_lines')->where('sales_order_id', $id)->orderBy('id')->get()
            ->map(static fn ($l) => new SalesOrderLine(
                (int) $l->id, (int) $l->product_id, Fixed::units((int) $l->quantity), Fixed::units((int) $l->list_price),
                Fixed::units((int) $l->discount_rate), Fixed::units((int) $l->unit_price),
                $l->reservation_id === null ? null : (int) $l->reservation_id,
                $l->cost_of_goods === null ? null : Fixed::units((int) $l->cost_of_goods),
            ))->all();

        return new SalesOrder((int) $o->id, $o->number, (int) $o->customer_id, (int) $o->warehouse_id, $o->status, $lines);
    }

    public function recordShipment(int $orderId, array $issuesByLineId): void
    {
        foreach ($issuesByLineId as $lineId => $issues) {
            $cost = Fixed::zero();
            foreach ($issues as $issue) {
                $cost = $cost->add($issue->cost);
                $this->db->table('sales_issue_lines')->insert([
                    'sales_order_line_id' => $lineId, 'stock_move_id' => $issue->stockMoveId, 'lot_no' => $issue->lotNo,
                    'quantity' => $issue->quantity->units, 'cost' => $issue->cost->units,
                ]);
            }
            $this->db->table('sales_order_lines')->where('id', $lineId)->update(['cost_of_goods' => $cost->units]);
        }
    }

    public function setStatus(int $orderId, string $status): void
    {
        $this->db->table('sales_orders')->where('id', $orderId)->update(['status' => $status, 'updated_at' => now()]);
    }

    public function createInvoice(int $orderId, Fixed $net, Fixed $tax, Fixed $gross): array
    {
        $terms = (int) $this->db->table('customers')->where('id', $this->db->table('sales_orders')->where('id', $orderId)->value('customer_id'))->value('terms_days');
        $id = $this->db->table('sales_invoices')->insertGetId([
            'due_date' => now()->addDays($terms)->format('Y-m-d'),
            'number' => 'PENDING-'.bin2hex(random_bytes(6)), 'sales_order_id' => $orderId,
            'net' => $net->units, 'tax' => $tax->units, 'gross' => $gross->units,
            'created_at' => now(),
        ]);
        $number = 'INV-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->db->table('sales_invoices')->where('id', $id)->update(['number' => $number]);

        return ['id' => $id, 'number' => $number];
    }
}
