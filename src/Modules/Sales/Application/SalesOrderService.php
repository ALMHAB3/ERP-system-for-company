<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use DomainException;
use Modules\Sales\Domain\CustomerRepository;
use Modules\Sales\Domain\Events\InvoiceIssued;
use Modules\Sales\Domain\Exceptions\InvalidOrderState;
use Modules\Sales\Domain\Exceptions\PricingApprovalRequired;
use Modules\Sales\Domain\Ports\StockAvailability;
use Modules\Sales\Domain\Pricing\PricingContext;
use Modules\Sales\Domain\Pricing\PricingEngineFactory;
use Modules\Sales\Domain\SalesOrder;
use Modules\Sales\Domain\SalesOrderLine;
use Modules\Sales\Domain\SalesOrderRepository;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Order -> reserve (ATP) -> ship (issue at cost) -> invoice (AR, revenue, VAT).
 * A sales order can never be accepted for stock that is not available: the reservation
 * happens in the same transaction as the order, so a failure leaves nothing behind.
 */
final class SalesOrderService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly SalesOrderRepository $orders,
        private readonly PricingEngineFactory $pricing,
        private readonly StockAvailability $stock,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    /** @param list<OrderLineRequest> $requests */
    public function place(int $customerId, int $warehouseId, array $requests, bool $allowBelowMargin = false): SalesOrder
    {
        if ($requests === []) {
            throw new DomainException('An order needs at least one line.');
        }

        return $this->tx->run(function () use ($customerId, $warehouseId, $requests, $allowBelowMargin): SalesOrder {
            $customer = $this->customers->find($customerId) ?? throw new DomainException("Customer {$customerId} not found.");
            $engine = $this->pricing->forCategory($customer->category);

            $lines = [];
            foreach ($requests as $request) {
                $quote = $engine->quote(new PricingContext($customer->id, $customer->category, $request->productId, $warehouseId, $request->quantity));
                if ($quote->belowMinimumMargin && ! $allowBelowMargin) {
                    throw new PricingApprovalRequired("Product {$request->productId} would sell below the minimum margin; manager approval required.");
                }
                $reservationId = $this->stock->reserve($request->productId, $warehouseId, $request->quantity, "sales_order:customer:{$customerId}");
                $lines[] = new SalesOrderLine(null, $request->productId, $request->quantity, $quote->listPrice, $quote->discountRate, $quote->unitPrice, $reservationId);
            }

            return $this->orders->create(new SalesOrder(null, '', $customerId, $warehouseId, 'reserved', $lines));
        });
    }

    public function ship(int $orderId): SalesOrder
    {
        return $this->tx->run(function () use ($orderId): SalesOrder {
            $order = $this->load($orderId, 'reserved');
            $costs = [];
            foreach ($order->lines as $line) {
                $costs[$line->id] = $this->stock->issue($line->reservationId, "sales_order:{$order->number}", "sales_order:{$orderId}:line:{$line->id}:ship");
            }
            $this->orders->recordShipment($orderId, $costs);
            $this->orders->setStatus($orderId, 'shipped');

            return $this->orders->lock($orderId);
        });
    }

    public function invoice(int $orderId): int
    {
        return $this->tx->run(function () use ($orderId): int {
            $order = $this->load($orderId, 'shipped');
            $net = $order->net();
            $tax = $net->mul(Fixed::parse((string) config('erp.vat_rate')));
            $gross = $net->add($tax);

            $invoice = $this->orders->createInvoice($orderId, $net, $tax, $gross);
            $this->orders->setStatus($orderId, 'invoiced');
            $this->events->dispatch(new InvoiceIssued($invoice['id'], $invoice['number'], $orderId, $net, $tax, $gross));

            return $invoice['id'];
        });
    }

    public function cancel(int $orderId): void
    {
        $this->tx->run(function () use ($orderId): void {
            $order = $this->load($orderId, 'reserved');
            foreach ($order->lines as $line) {
                $this->stock->release($line->reservationId);
            }
            $this->orders->setStatus($orderId, 'cancelled');
        });
    }

    public function find(int $orderId): ?SalesOrder
    {
        return $this->orders->find($orderId);
    }

    private function load(int $orderId, string $expected): SalesOrder
    {
        $order = $this->orders->lock($orderId) ?? throw new InvalidOrderState("Sales order {$orderId} not found.");
        if ($order->status !== $expected) {
            throw new InvalidOrderState("Sales order {$orderId} is {$order->status}, expected {$expected}.");
        }

        return $order;
    }
}
