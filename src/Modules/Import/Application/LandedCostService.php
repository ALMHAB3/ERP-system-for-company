<?php

declare(strict_types=1);

namespace Modules\Import\Application;

use DomainException;
use Modules\Import\Domain\Events\LandedCostFinalized;
use Modules\Import\Domain\LandedCostCalculator;
use Modules\Import\Domain\LandedCostResult;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentRepository;
use Modules\Import\Domain\StockReceiver;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;

final class LandedCostService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly LandedCostCalculator $calculator,
        private readonly StockReceiver $stock,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    public function open(Shipment $shipment): Shipment
    {
        return $this->tx->run(fn () => $this->shipments->create($shipment));
    }

    /** Calculates and freezes the landed cost of every line. A shipment can only be costed once. */
    public function finalize(int $shipmentId): LandedCostResult
    {
        return $this->tx->run(function () use ($shipmentId): LandedCostResult {
            $shipment = $this->load($shipmentId);
            if ($shipment->status !== 'open') {
                throw new DomainException("Shipment {$shipmentId} is already {$shipment->status}.");
            }

            $result = $this->calculator->calculate($shipment->toRequest());
            $this->shipments->saveAllocations($shipmentId, $result);
            $this->shipments->setStatus($shipmentId, 'costed');
            $this->events->dispatch(new LandedCostFinalized($shipmentId, $result));

            return $result;
        });
    }

    /**
     * Receives a costed shipment into a warehouse: each line enters the inventory ledger at its exact
     * landed total, so moving-average cost reflects freight, insurance and duty. Idempotent per line.
     */
    public function receive(int $shipmentId, int $warehouseId): void
    {
        $this->tx->run(function () use ($shipmentId, $warehouseId): void {
            $shipment = $this->load($shipmentId);
            if ($shipment->status !== 'costed') {
                throw new DomainException("Shipment {$shipmentId} must be costed before receiving (status: {$shipment->status}).");
            }
            $result = $this->calculator->calculate($shipment->toRequest());

            foreach ($shipment->lines as $line) {
                $this->stock->receive(
                    $line->productId,
                    $warehouseId,
                    $line->quantity,
                    $result->line($line->id)->total,
                    "shipment:{$shipment->reference}",
                    "shipment:{$shipmentId}:line:{$line->id}",
                );
            }
            $this->shipments->setStatus($shipmentId, 'received');
        });
    }

    private function load(int $shipmentId): Shipment
    {
        return $this->shipments->lock($shipmentId) ?? throw new DomainException("Shipment {$shipmentId} not found.");
    }
}
