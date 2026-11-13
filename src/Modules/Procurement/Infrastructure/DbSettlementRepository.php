<?php

declare(strict_types=1);

namespace Modules\Procurement\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Procurement\Domain\SettlementRepository;
use Modules\Shared\Domain\Fixed;

final class DbSettlementRepository implements SettlementRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function shipmentStatus(string $receiptReference): ?string
    {
        if (! str_starts_with($receiptReference, 'shipment:')) {
            return null;
        }
        $s = $this->db->table('shipments')->where('reference', substr($receiptReference, 9))->lockForUpdate()->value('status');

        return $s === null ? null : (string) $s;
    }

    public function isSettled(string $receiptReference): bool
    {
        return $this->db->table('shipment_cost_settlements')->where('receipt_reference', $receiptReference)->exists();
    }

    public function linkedInvoices(string $receiptReference): array
    {
        return $this->db->table('supplier_invoices')->where('receipt_reference', $receiptReference)->orderBy('id')->lockForUpdate()->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'number' => $r->number, 'subtotal' => Fixed::units((int) $r->subtotal), 'tax' => Fixed::units((int) $r->tax), 'paid' => Fixed::units((int) $r->paid)])->all();
    }

    public function allocations(int $invoiceId): array
    {
        return $this->db->table('supplier_payment_allocations')->where('supplier_invoice_id', $invoiceId)->orderBy('id')->get()
            ->map(fn ($a) => ['cash_base' => Fixed::units((int) $a->cash_base), 'invoice_base' => Fixed::units((int) $a->invoice_base)])->all();
    }

    public function receiptLines(string $receiptReference): array
    {
        return $this->db->table('stock_moves')->where('reference', $receiptReference)->where('type', 'receipt')->orderBy('id')->get()
            ->map(fn ($m) => ['product_id' => (int) $m->product_id, 'warehouse_id' => (int) $m->warehouse_id, 'lot_no' => (string) $m->lot_no,
                'quantity' => Fixed::units((int) $m->quantity), 'total_cost' => Fixed::units((int) $m->total_cost)])->all();
    }

    public function lotHoldings(int $productId, string $lotNo): array
    {
        return $this->db->table('stock_lots')->where('product_id', $productId)->where('lot_no', $lotNo)->where('on_hand', '>', 0)->orderBy('warehouse_id')->lockForUpdate()->get()
            ->map(fn ($l) => ['warehouse_id' => (int) $l->warehouse_id, 'on_hand' => Fixed::units((int) $l->on_hand)])->all();
    }

    public function insertSettlement(string $receiptReference, Fixed $fx, Fixed $cleared, Fixed $delta, Fixed $inventoryPart, Fixed $cogsPart, ?int $userId, array $lines): int
    {
        $id = $this->db->table('shipment_cost_settlements')->insertGetId([
            'receipt_reference' => $receiptReference, 'fx_difference' => $fx->units, 'cleared_variance' => $cleared->units, 'delta' => $delta->units,
            'inventory_part' => $inventoryPart->units, 'cogs_part' => $cogsPart->units, 'settled_by' => $userId, 'created_at' => now(),
        ]);
        foreach ($lines as $l) {
            $this->db->table('shipment_settlement_lines')->insert([
                'shipment_cost_settlement_id' => $id, 'product_id' => $l['product_id'], 'lot_no' => $l['lot_no'],
                'delta' => $l['delta']->units, 'inventory_part' => $l['inventory_part']->units, 'cogs_part' => $l['cogs_part']->units,
            ]);
        }

        return $id;
    }
}
