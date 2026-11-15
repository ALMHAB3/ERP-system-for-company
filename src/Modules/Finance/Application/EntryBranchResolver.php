<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use Illuminate\Database\ConnectionInterface;

/**
 * Which branch a journal entry belongs to, from the document that produced it (Strategy per source type).
 * Null means head office / the whole company (supplier bills, imports, bank charges, revaluation ...).
 */
final class EntryBranchResolver
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function branchOf(string $sourceType, string $sourceId): ?int
    {
        if (! ctype_digit($sourceId)) {
            return null;
        }
        $id = (int) $sourceId;
        $branch = match ($sourceType) {
            'stock_move' => $this->db->table('stock_moves')->where('id', $id)->value('warehouse_id'),
            'sales_invoice' => $this->db->table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->where('i.id', $id)->value('o.warehouse_id'),
            'credit_note' => $this->db->table('credit_notes as n')->join('sales_invoices as i', 'i.id', '=', 'n.sales_invoice_id')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
                ->where('n.id', $id)->value('o.warehouse_id'),
            'customer_receipt' => $this->singleBranchOfReceipt($id),
            'employee_advance' => $this->db->table('employee_advances as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.id', $id)->value('e.warehouse_id'),
            default => null,
        };

        return $branch === null ? null : (int) $branch;
    }

    /** A receipt belongs to a branch only when every invoice it pays was issued by that branch. */
    private function singleBranchOfReceipt(int $receiptId): ?int
    {
        $branches = $this->db->table('receipt_allocations as a')->join('sales_invoices as i', 'i.id', '=', 'a.sales_invoice_id')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->where('a.customer_receipt_id', $receiptId)->distinct()->pluck('o.warehouse_id')->all();

        return count($branches) === 1 ? (int) $branches[0] : null;
    }
}
