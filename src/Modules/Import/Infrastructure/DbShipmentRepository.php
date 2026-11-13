<?php

declare(strict_types=1);

namespace Modules\Import\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\LandedCostResult;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentLine;
use Modules\Import\Domain\ShipmentRepository;
use Modules\Shared\Domain\Fixed;

final class DbShipmentRepository implements ShipmentRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(Shipment $shipment): Shipment
    {
        $id = $this->db->table('shipments')->insertGetId([
            'reference' => $shipment->reference, 'currency' => $shipment->currency,
            'exchange_rate' => $shipment->exchangeRate->units, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lines = [];
        foreach ($shipment->lines as $l) {
            $lineId = $this->db->table('shipment_lines')->insertGetId([
                'shipment_id' => $id, 'product_id' => $l->productId, 'quantity' => $l->quantity->units,
                'unit_price' => $l->unitPrice->units, 'volume_cbm' => $l->volumeCbm->units,
                'weight_kg' => $l->weightKg->units, 'duty_rate' => $l->dutyRate->units,
                'lot_no' => $l->lotNo, 'expiry_date' => $l->expiryDate?->format('Y-m-d'),
            ]);
            $lines[] = new ShipmentLine($lineId, $l->productId, $l->quantity, $l->unitPrice, $l->volumeCbm, $l->weightKg, $l->dutyRate, $l->lotNo, $l->expiryDate);
        }
        foreach ($shipment->costItems as $c) {
            $this->db->table('shipment_cost_items')->insert([
                'shipment_id' => $id, 'code' => $c->code, 'amount' => $c->amount->units,
                'basis' => $c->basis->value, 'in_customs_value' => $c->inCustomsValue,
            ]);
        }

        return new Shipment($id, $shipment->reference, $shipment->currency, $shipment->exchangeRate, 'open', $lines, $shipment->costItems);
    }

    public function lock(int $id): ?Shipment
    {
        return $this->load($id, true);
    }

    public function find(int $id): ?Shipment
    {
        return $this->load($id, false);
    }

    public function allocations(int $shipmentId): array
    {
        $out = [];
        foreach ($this->db->table('landed_cost_allocations')->where('shipment_id', $shipmentId)->orderBy('id')->get() as $a) {
            $out[(int) $a->shipment_line_id][$a->component] = (string) Fixed::units((int) $a->amount);
        }

        return $out;
    }

    private function load(int $id, bool $lock): ?Shipment
    {
        $query = $this->db->table('shipments')->where('id', $id);
        $s = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $s) {
            return null;
        }
        $lines = $this->db->table('shipment_lines')->where('shipment_id', $id)->orderBy('id')->get()
            ->map(static fn ($l) => new ShipmentLine(
                (int) $l->id, (int) $l->product_id, Fixed::units((int) $l->quantity), Fixed::units((int) $l->unit_price),
                Fixed::units((int) $l->volume_cbm), Fixed::units((int) $l->weight_kg), Fixed::units((int) $l->duty_rate),
                $l->lot_no, $l->expiry_date ? new \DateTimeImmutable($l->expiry_date) : null,
            ))->all();
        $items = $this->db->table('shipment_cost_items')->where('shipment_id', $id)->orderBy('id')->get()
            ->map(static fn ($c) => new CostItem($c->code, Fixed::units((int) $c->amount), CostBasis::from($c->basis), (bool) $c->in_customs_value))->all();

        return new Shipment((int) $s->id, $s->reference, $s->currency, Fixed::units((int) $s->exchange_rate), $s->status, $lines, $items);
    }

    public function saveAllocations(int $shipmentId, LandedCostResult $result): void
    {
        $this->db->table('landed_cost_allocations')->where('shipment_id', $shipmentId)->delete();
        $rows = [];
        foreach ($result->lines as $line) {
            foreach ($line->components as $component => $amount) {
                $rows[] = ['shipment_id' => $shipmentId, 'shipment_line_id' => $line->lineId, 'component' => $component, 'amount' => $amount->units];
            }
        }
        $this->db->table('landed_cost_allocations')->insert($rows);
    }

    public function setStatus(int $shipmentId, string $status): void
    {
        $this->db->table('shipments')->where('id', $shipmentId)->update(['status' => $status, 'updated_at' => now()]);
    }
}
