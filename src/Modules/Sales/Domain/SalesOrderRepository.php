<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

use Modules\Sales\Domain\Ports\IssuedLine;
use Modules\Shared\Domain\Fixed;

interface SalesOrderRepository
{
    public function create(SalesOrder $order): SalesOrder;

    public function lock(int $id): ?SalesOrder;

    public function find(int $id): ?SalesOrder;

    /** @param array<int, list<IssuedLine>> $issuesByLineId */
    public function recordShipment(int $orderId, array $issuesByLineId): void;

    public function setStatus(int $orderId, string $status): void;

    /** @return array{id:int,number:string} */
    public function createInvoice(int $orderId, Fixed $net, Fixed $tax, Fixed $gross): array;
}
