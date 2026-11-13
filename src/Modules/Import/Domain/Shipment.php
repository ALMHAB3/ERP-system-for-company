<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class Shipment
{
    /**
     * @param  list<ShipmentLine>  $lines
     * @param  list<CostItem>  $costItems
     */
    public function __construct(
        public ?int $id,
        public string $reference,
        public string $currency,
        public Fixed $exchangeRate,
        public string $status, // open | costed | received
        public array $lines,
        public array $costItems,
    ) {}

    public function toRequest(): LandedCostRequest
    {
        return new LandedCostRequest(
            array_map(static fn (ShipmentLine $l) => $l->toCostable(), $this->lines),
            $this->costItems,
            $this->exchangeRate,
        );
    }
}
