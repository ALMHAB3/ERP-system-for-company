<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

interface PurchaseOrderRepository
{
    public function create(PurchaseOrder $order): PurchaseOrder;

    public function lock(int $id): ?PurchaseOrder;

    public function find(int $id): ?PurchaseOrder;

    public function setStatus(int $id, PurchaseOrderStatus $status, ?int $approvedBy = null): void;
}
