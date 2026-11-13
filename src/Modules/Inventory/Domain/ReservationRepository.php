<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

interface ReservationRepository
{
    public function create(Reservation $reservation): Reservation;

    /** Loads and row-locks a reservation. */
    public function lock(int $id): ?Reservation;

    public function setStatus(int $id, ReservationStatus $status): void;

    public function activeTotal(int $productId, int $warehouseId): Fixed;

    /** @return list<int> */
    public function expiredActiveIds(DateTimeImmutable $now): array;
}
