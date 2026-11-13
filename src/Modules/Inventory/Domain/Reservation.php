<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

final readonly class Reservation
{
    public function __construct(
        public ?int $id,
        public int $productId,
        public int $warehouseId,
        public Fixed $quantity,
        public string $reference,
        public ReservationStatus $status,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
