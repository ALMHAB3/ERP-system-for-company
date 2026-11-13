<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\Reservation;
use Modules\Inventory\Domain\ReservationRepository;
use Modules\Inventory\Domain\ReservationStatus;
use Modules\Shared\Domain\Fixed;

final class EloquentReservationRepository implements ReservationRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(Reservation $reservation): Reservation
    {
        $id = $this->db->table('stock_reservations')->insertGetId([
            'product_id' => $reservation->productId,
            'warehouse_id' => $reservation->warehouseId,
            'quantity' => $reservation->quantity->units,
            'reference' => $reservation->reference,
            'status' => $reservation->status->value,
            'expires_at' => $reservation->expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new Reservation($id, $reservation->productId, $reservation->warehouseId, $reservation->quantity,
            $reservation->reference, $reservation->status, $reservation->expiresAt);
    }

    public function lock(int $id): ?Reservation
    {
        $row = $this->db->table('stock_reservations')->where('id', $id)->lockForUpdate()->first();
        if (! $row) {
            return null;
        }

        return new Reservation(
            (int) $row->id, (int) $row->product_id, (int) $row->warehouse_id, Fixed::units((int) $row->quantity),
            $row->reference, ReservationStatus::from($row->status),
            $row->expires_at ? new DateTimeImmutable($row->expires_at) : null,
        );
    }

    public function setStatus(int $id, ReservationStatus $status): void
    {
        $this->db->table('stock_reservations')->where('id', $id)
            ->update(['status' => $status->value, 'updated_at' => now()]);
    }

    public function activeTotal(int $productId, int $warehouseId): Fixed
    {
        return Fixed::units((int) $this->db->table('stock_reservations')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->where('status', ReservationStatus::Active->value)->sum('quantity'));
    }

    public function expiredActiveIds(DateTimeImmutable $now): array
    {
        return $this->db->table('stock_reservations')
            ->where('status', ReservationStatus::Active->value)
            ->whereNotNull('expires_at')->where('expires_at', '<=', $now->format('Y-m-d H:i:s'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
