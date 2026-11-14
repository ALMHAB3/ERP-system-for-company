<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Domain\PaymentTerms;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/** One screen, one click: reserve, ship and invoice in a single transaction (all or nothing). */
final class QuickSaleService
{
    public function __construct(private readonly SalesOrderService $orders, private readonly TransactionRunner $tx) {}

    /**
     * @param  list<OrderLineRequest>  $lines
     * @return array{order_id:int, order_number:string, invoice_id:int, invoice_number:string, gross:string}
     */
    public function sell(int $customerId, ?string $buyerName, int $warehouseId, array $lines, PaymentTerms $terms, ?string $reference, int $userId, ?Fixed $discountRate = null, ?Fixed $paid = null, bool $allowBelowMargin = false): array
    {
        return $this->tx->run(function () use ($customerId, $buyerName, $warehouseId, $lines, $terms, $reference, $userId, $discountRate, $paid, $allowBelowMargin): array {
            $order = $this->orders->place($customerId, $warehouseId, $lines, $allowBelowMargin, false, $terms, $reference, $discountRate, $paid);
            DB::table('sales_orders')->where('id', $order->id)->update(['customer_name' => $buyerName, 'created_by' => $userId]);
            $this->orders->ship((int) $order->id);
            $invoiceId = $this->orders->invoice((int) $order->id, $reference, $paid);
            $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->first(['number', 'gross']);

            return ['order_id' => (int) $order->id, 'order_number' => $order->number, 'invoice_id' => $invoiceId, 'invoice_number' => $invoice->number, 'gross' => (string) Fixed::units((int) $invoice->gross)];
        });
    }
}
