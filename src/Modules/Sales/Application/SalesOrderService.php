<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Carbon\CarbonImmutable;
use DomainException;
use Modules\Sales\Domain\CustomerRepository;
use Modules\Sales\Domain\Events\InvoiceIssued;
use Modules\Sales\Domain\Exceptions\CreditLimitExceeded;
use Modules\Sales\Domain\Exceptions\CreditNeedsRegisteredCustomer;
use Modules\Sales\Domain\Exceptions\InvalidOrderState;
use Modules\Sales\Domain\Exceptions\PricingApprovalRequired;
use Modules\Sales\Domain\PaymentMethod;
use Modules\Sales\Domain\PaymentTerms;
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
        private readonly ReceivablesService $receivables,
    ) {}

    /** @param list<OrderLineRequest> $requests */
    public function place(int $customerId, int $warehouseId, array $requests, bool $allowBelowMargin = false, bool $overrideCredit = false, ?PaymentTerms $terms = null, ?string $paymentReference = null, ?Fixed $extraDiscount = null, ?Fixed $paidNow = null): SalesOrder
    {
        if ($requests === []) {
            throw new DomainException('An order needs at least one line.');
        }

        return $this->tx->run(function () use ($customerId, $warehouseId, $requests, $allowBelowMargin, $overrideCredit, $terms, $paymentReference, $extraDiscount, $paidNow): SalesOrder {
            $customer = $this->customers->find($customerId) ?? throw new DomainException("Customer {$customerId} not found.");
            $terms ??= new PaymentTerms;
            // Credit means the money comes later: only for a customer the company has registered and can chase.
            if ($terms->method === PaymentMethod::Credit && $customer->isWalkIn) {
                throw new CreditNeedsRegisteredCustomer('البيع بدين على العميل لا يتم إلا لعميل مسجَّل لدينا. سجّل العميل أولاً، أو بِع نقداً أو بتحويل مصرفي.');
            }
            if ($terms->method->isImmediate() && $terms->cashAccount === null) {
                throw new DomainException('Cash and bank-transfer sales need the cash account or bank account the money goes to.');
            }
            $engine = $this->pricing->forCategory($customer->category);

            $lines = [];
            foreach ($requests as $request) {
                $context = new PricingContext($customer->id, $customer->category, $request->productId, $warehouseId, $request->quantity);
                $quote = $engine->quote($context);
                $unit = $quote->unitPrice;
                $discountRate = $quote->discountRate;
                $below = $quote->belowMinimumMargin;
                if ($extraDiscount !== null && $extraDiscount->isPositive()) {   // a discount given at the counter, on top of the price list
                    $unit = $unit->sub($unit->mul($extraDiscount));
                    $below = $engine->belowFloor($context, $unit);
                    $discountRate = $quote->listPrice->isPositive() ? Fixed::of(1)->sub($unit->div($quote->listPrice)) : $discountRate;
                }
                if ($below && ! $allowBelowMargin) {
                    throw new PricingApprovalRequired("سعر الصنف رقم {$request->productId} بعد الخصم أقل من الحد الأدنى للهامش؛ يلزم موافقة من يملك صلاحية البيع تحت الحد الأدنى.");
                }
                $reservationId = $this->stock->reserve($request->productId, $warehouseId, $request->quantity, "sales_order:customer:{$customerId}");
                $lines[] = new SalesOrderLine(null, $request->productId, $request->quantity, $quote->listPrice, $discountRate, $unit, $reservationId);
            }

            $order = new SalesOrder(null, '', $customerId, $warehouseId, 'reserved', $lines, $terms->method, $terms->cashAccount, $paymentReference);
            $net = $order->net();
            $gross = $net->add($net->mul(Fixed::parse((string) config('erp.vat_rate'))));
            // what stays owed by the customer: everything on credit, or the unpaid rest of a part-paid cash / transfer sale
            $owed = $terms->method === PaymentMethod::Credit ? $gross : ($paidNow !== null && $paidNow->lt($gross) ? $gross->sub($paidNow) : Fixed::zero());
            if ($owed->isPositive() && $customer->creditLimit->isPositive() && ! $overrideCredit) {   // limit 0 = no limit
                $exposure = $this->receivables->exposure($customerId);
                if ($exposure->add($owed)->gt($customer->creditLimit)) {
                    throw new CreditLimitExceeded("تجاوز حد الدين المسموح للعميل ({$customer->creditLimit}): المستحق الحالي {$exposure} + هذه العملية {$owed}.");
                }
            }

            return $this->orders->create($order);
        });
    }

    public function ship(int $orderId): SalesOrder
    {
        return $this->tx->run(function () use ($orderId): SalesOrder {
            $order = $this->load($orderId, 'reserved');
            $issues = [];
            foreach ($order->lines as $line) {
                $issues[$line->id] = $this->stock->issue($line->reservationId, "sales_order:{$order->number}", "sales_order:{$orderId}:line:{$line->id}:ship");
            }
            $this->orders->recordShipment($orderId, $issues);
            $this->orders->setStatus($orderId, 'shipped');

            return $this->orders->lock($orderId);
        });
    }

    public function invoice(int $orderId, ?string $paymentReference = null, ?Fixed $paid = null): int
    {
        return $this->tx->run(function () use ($orderId, $paymentReference, $paid): int {
            $order = $this->load($orderId, 'shipped');
            $net = $order->net();
            $tax = $net->mul(Fixed::parse((string) config('erp.vat_rate')));
            $gross = $net->add($tax);

            $invoice = $this->orders->createInvoice($orderId, $net, $tax, $gross);
            $this->orders->setStatus($orderId, 'invoiced');
            $this->events->dispatch(new InvoiceIssued($invoice['id'], $invoice['number'], $orderId, $net, $tax, $gross));

            // Cash and bank-transfer sales are paid at the counter: the receipt is booked with the invoice, into the till or the chosen bank.
            if ($order->paymentMethod->isImmediate()) {
                $amount = $paid ?? $gross;
                if ($amount->gt($gross)) {
                    throw new DomainException("المبلغ المدفوع ({$amount}) أكبر من إجمالي الفاتورة ({$gross}).");
                }
                if ($amount->lt($gross) && ($this->customers->find($order->customerId)?->isWalkIn ?? true)) {
                    throw new CreditNeedsRegisteredCustomer('المدفوع أقل من إجمالي الفاتورة: يبقى الباقي ديناً على العميل، ولا يكون الدين إلا على عميل مسجَّل. اختر العميل أو ادفع كامل المبلغ.');
                }
                if ($amount->isPositive()) {
                    $this->receivables->receive($order->customerId, CarbonImmutable::today(), $order->paymentMethod->value,
                        $paymentReference ?? $order->paymentReference ?? $order->number, [$invoice['id'] => $amount], $order->cashAccount);
                }
            }

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
