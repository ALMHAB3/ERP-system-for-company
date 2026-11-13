<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Sales\Domain\Events\CreditNoteIssued;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Customer returns of an invoiced order: goods go back into the SAME lots they were shipped from,
 * at the cost they left with (so COGS reverses exactly), and a credit note reduces the invoice balance.
 */
final class SalesReturnService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly InventoryLedger $inventory,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    /** @param array<int,Fixed> $quantities sales order line id => quantity returned @return int credit note id */
    public function returnGoods(int $orderId, array $quantities, string $reason): int
    {
        return $this->tx->run(function () use ($orderId, $quantities, $reason): int {
            $order = $this->db->table('sales_orders')->where('id', $orderId)->lockForUpdate()->first() ?? throw new DomainException("Order {$orderId} not found.");
            $invoice = $this->db->table('sales_invoices')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            if ($order->status !== 'invoiced' || ! $invoice) {
                throw new DomainException('Only invoiced orders can be returned.');
            }
            $vat = Fixed::parse((string) config('erp.vat_rate'));

            $net = Fixed::zero();
            $plan = [];   // [orderLineId, issueRow, qty, cost, unitPrice]
            foreach ($quantities as $lineId => $qty) {
                $line = $this->db->table('sales_order_lines')->where('id', $lineId)->where('sales_order_id', $orderId)->first()
                    ?? throw new DomainException("Line {$lineId} is not on order {$orderId}.");
                if (! $qty->isPositive()) {
                    throw new DomainException('Returned quantity must be positive.');
                }
                $remaining = $qty;
                // Newest shipments are returned first; each issue row can only be returned up to what is still out.
                $issues = $this->db->table('sales_issue_lines')->where('sales_order_line_id', $lineId)->orderByDesc('id')->get();
                foreach ($issues as $issue) {
                    if (! $remaining->isPositive()) {
                        break;
                    }
                    $returned = Fixed::units((int) $this->db->table('sales_return_lines')->where('sales_issue_line_id', $issue->id)->sum('quantity'));
                    $left = Fixed::units((int) $issue->quantity)->sub($returned);
                    if (! $left->isPositive()) {
                        continue;
                    }
                    $take = $remaining->lt($left) ? $remaining : $left;
                    $issuedQty = Fixed::units((int) $issue->quantity);
                    $returnedCost = Fixed::units((int) $this->db->table('sales_return_lines')->where('sales_issue_line_id', $issue->id)->sum('cost'));
                    $cost = $take->equals($left)
                        ? Fixed::units((int) $issue->cost)->sub($returnedCost)     // last piece: exact remaining cost
                        : Fixed::units(Fixed::mulDiv((int) $issue->cost, $take->units, $issuedQty->units));
                    $plan[] = [(int) $lineId, $issue, $take, $cost];
                    $remaining = $remaining->sub($take);
                }
                if ($remaining->isPositive()) {
                    throw new DomainException("Line {$lineId}: cannot return more than was shipped and not yet returned.");
                }
                $net = $net->add($qty->mul(Fixed::units((int) $line->unit_price)));
            }

            $tax = $net->mul($vat);
            $gross = $net->add($tax);
            $open = Fixed::units((int) $invoice->gross - (int) $invoice->paid - (int) $invoice->credited);
            // A credit larger than what is still owed would leave the customer in credit; refunds are a separate process.
            if ($gross->gt($open)) {
                throw new DomainException("Credit note {$gross} exceeds the open invoice balance {$open}; settle it with a refund process first.");
            }

            $noteId = $this->db->table('credit_notes')->insertGetId([
                'number' => 'CN-PENDING-'.bin2hex(random_bytes(6)), 'sales_invoice_id' => $invoice->id,
                'net' => $net->units, 'tax' => $tax->units, 'gross' => $gross->units, 'reason' => $reason, 'created_at' => now(),
            ]);
            $number = 'CN-'.str_pad((string) $noteId, 6, '0', STR_PAD_LEFT);
            $this->db->table('credit_notes')->where('id', $noteId)->update(['number' => $number]);

            foreach ($plan as [$lineId, $issue, $take, $cost]) {
                $lot = $this->db->table('stock_lots')->where('lot_no', $issue->lot_no)
                    ->where('product_id', $this->db->table('sales_order_lines')->where('id', $lineId)->value('product_id'))
                    ->where('warehouse_id', $order->warehouse_id)->first();
                $this->inventory->record(new StockMoveData(
                    (int) $this->db->table('sales_order_lines')->where('id', $lineId)->value('product_id'), (int) $order->warehouse_id,
                    MoveType::SalesReturn, $take, null, "credit_note:{$number}", lotNo: $issue->lot_no, totalCost: $cost,
                    idempotencyKey: "credit_note:{$noteId}:issue:{$issue->id}",
                    expiryDate: $lot?->expiry_date ? new DateTimeImmutable($lot->expiry_date) : null,
                ));
                $this->db->table('sales_return_lines')->insert([
                    'credit_note_id' => $noteId, 'sales_order_line_id' => $lineId, 'sales_issue_line_id' => $issue->id,
                    'quantity' => $take->units, 'cost' => $cost->units,
                ]);
            }

            $this->db->table('sales_invoices')->where('id', $invoice->id)->update(['credited' => (int) $invoice->credited + $gross->units]);
            $this->events->dispatch(new CreditNoteIssued($noteId, $number, (int) $invoice->id, $net, $tax, $gross));

            return $noteId;
        });
    }
}
