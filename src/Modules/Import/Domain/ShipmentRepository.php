<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

interface ShipmentRepository
{
    public function create(Shipment $shipment): Shipment;

    /** Loads a shipment under a row lock. */
    public function lock(int $id): ?Shipment;

    public function saveAllocations(int $shipmentId, LandedCostResult $result): void;

    public function setStatus(int $shipmentId, string $status): void;
}
