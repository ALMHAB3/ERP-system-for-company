<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Application\SerialService;
use Modules\Sales\Domain\PaymentTerms;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/** One screen, one click: reserve, take the serials, ship and invoice in a single transaction (all or nothing). */
final class QuickSaleService
{
    public function __construct(
        private readonly SalesOrderService $orders,
        private readonly SerialService $serials,
        private readonly TransactionRunner $tx,
    ) {}

    /**
     * @param  list<OrderLineRequest>  $lines
     * @param  array<int, list<string>>  $serialsByLine  line position => serial numbers
     * @return array{order_id:int, order_number:string, invoice_id:int, invoice_number:string, gross:string}
     */
    public function sell(int $customerId, ?string $buyerName, int $warehouseId, array $lines, array $serialsByLine, PaymentTerms $terms, ?string $reference, int $userId): array
    {
        return $this->tx->run(function () use ($customerId, $buyerName, $warehouseId, $lines, $serialsByLine, $terms, $reference, $userId): array {
            $order = $this->orders->place($customerId, $warehouseId, $lines, false, false, $terms, $reference);
            foreach ($serialsByLine as $position => $serials) {
                $lineId = $order->lines[$position]->id ?? throw new DomainException('Unknown line for serial numbers.');
                $this->serials->assign((int) $order->id, (int) $lineId, $serials);
            }
            DB::table('sales_orders')->where('id', $order->id)->update(['customer_name' => $buyerName, 'created_by' => $userId]);
            $this->orders->ship((int) $order->id);
            $invoiceId = $this->orders->invoice((int) $order->id, $reference);
            $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->first(['number', 'gross']);

            return ['order_id' => (int) $order->id, 'order_number' => $order->number, 'invoice_id' => $invoiceId, 'invoice_number' => $invoice->number, 'gross' => (string) Fixed::units((int) $invoice->gross)];
        });
    }
}
