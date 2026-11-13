<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

/** Stock of one product in one warehouse that arrived together (one shipment / supplier batch). */
final readonly class StockLot
{
    public const DEFAULT = 'NOLOT';

    public function __construct(
        public string $lotNo,
        public ?DateTimeImmutable $expiryDate,
        public DateTimeImmutable $receivedAt,
        public Fixed $onHand,
        public Fixed $value,
    ) {}

    public function isExpired(DateTimeImmutable $today): bool
    {
        return $this->expiryDate !== null && $this->expiryDate < $today;
    }

    public function with(Fixed $onHand, Fixed $value): self
    {
        return new self($this->lotNo, $this->expiryDate, $this->receivedAt, $onHand, $value);
    }
}
