<?php

declare(strict_types=1);

namespace Modules\Procurement\Application;

use Modules\Import\Domain\StockReceiver;
use Modules\Procurement\Domain\ApprovalPolicy;
use Modules\Procurement\Domain\Exceptions\InvalidPurchaseOrderTransition;
use Modules\Procurement\Domain\PurchaseOrder;
use Modules\Procurement\Domain\PurchaseOrderLine;
use Modules\Procurement\Domain\PurchaseOrderRepository;
use Modules\Procurement\Domain\PurchaseOrderStatus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/** Draft -> pending approval -> approved -> received. Separation of duties: the creator cannot approve. */
final class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly ApprovalPolicy $policy,
        private readonly StockReceiver $stock,
        private readonly TransactionRunner $tx,
    ) {}

    /** @param list<PurchaseOrderLine> $lines */
    public function draft(string $number, string $supplier, string $currency, Fixed $exchangeRate, array $lines, int $createdBy): PurchaseOrder
    {
        $order = new PurchaseOrder(null, $number, $supplier, $currency, $exchangeRate, PurchaseOrderStatus::Draft, 1, $lines);
        $order = new PurchaseOrder(null, $number, $supplier, $currency, $exchangeRate, PurchaseOrderStatus::Draft,
            $this->policy->requiredLevel($order->total()), $lines, $createdBy);

        return $this->tx->run(fn () => $this->orders->create($order));
    }

    public function submit(int $id): void
    {
        $this->transition($id, PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval);
    }

    public function approve(int $id, int $approverId, int $approverLevel): void
    {
        $this->tx->run(function () use ($id, $approverId, $approverLevel): void {
            $order = $this->loadIn($id, PurchaseOrderStatus::PendingApproval);
            if ($approverId === $order->createdBy) {
                throw new InvalidPurchaseOrderTransition('The creator of a purchase order cannot approve it.');
            }
            if ($approverLevel < $order->requiredApprovalLevel) {
                throw new InvalidPurchaseOrderTransition("Approval level {$order->requiredApprovalLevel} required, approver has {$approverLevel}.");
            }
            $this->orders->setStatus($id, PurchaseOrderStatus::Approved, $approverId);
        });
    }

    /** Local purchase received straight into stock at the PO price (imports go through a Shipment instead). */
    public function receiveLocal(int $id, int $warehouseId): void
    {
        $this->tx->run(function () use ($id, $warehouseId): void {
            $order = $this->loadIn($id, PurchaseOrderStatus::Approved);
            foreach ($order->lines as $line) {
                $this->stock->receive(
                    $line->productId, $warehouseId, $line->quantity,
                    $line->total()->mul($order->exchangeRate),
                    "po:{$order->number}", "po:{$id}:line:{$line->id}",
                );
            }
            $this->orders->setStatus($id, PurchaseOrderStatus::Received);
        });
    }

    public function find(int $id): ?PurchaseOrder
    {
        return $this->orders->find($id);
    }

    private function transition(int $id, PurchaseOrderStatus $from, PurchaseOrderStatus $to): void
    {
        $this->tx->run(function () use ($id, $from, $to): void {
            $this->loadIn($id, $from);
            $this->orders->setStatus($id, $to);
        });
    }

    private function loadIn(int $id, PurchaseOrderStatus $expected): PurchaseOrder
    {
        $order = $this->orders->lock($id) ?? throw new InvalidPurchaseOrderTransition("Purchase order {$id} not found.");
        if ($order->status !== $expected) {
            throw new InvalidPurchaseOrderTransition("Purchase order {$id} is {$order->status->value}, expected {$expected->value}.");
        }

        return $order;
    }
}
