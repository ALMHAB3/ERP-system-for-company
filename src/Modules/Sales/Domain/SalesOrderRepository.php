<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

use Modules\Shared\Domain\Fixed;

interface SalesOrderRepository
{
    public function create(SalesOrder $order): SalesOrder;

    public function lock(int $id): ?SalesOrder;

    public function find(int $id): ?SalesOrder;

    public function recordShipment(int $orderId, array $costByLineId): void;

    public function setStatus(int $orderId, string $status): void;

    /** @return array{id:int,number:string} */
    public function createInvoice(int $orderId, Fixed $net, Fixed $tax, Fixed $gross): array;
}
