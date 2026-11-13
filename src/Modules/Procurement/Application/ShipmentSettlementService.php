<?php

declare(strict_types=1);

namespace Modules\Procurement\Application;

use DomainException;
use Modules\Procurement\Domain\Events\ShipmentCostSettled;
use Modules\Procurement\Domain\PayablesRepository;
use Modules\Procurement\Domain\SettlementRepository;
use Modules\Procurement\Domain\StockRevaluation;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Final actual cost of an import shipment.
 *
 * Stock enters at the cost estimated when the shipment was received (shipment rate). Once every supplier invoice
 * of the shipment is PAID and GR/IR is fully matched, the real cost is known:
 *
 *     delta = FX difference of the payments (ex-VAT)  -  GR/IR variance that was cleared
 *
 * delta is spread over the shipment's lots in proportion to their booked cost. The share still in stock raises (or lowers)
 * the lot value; the share already sold goes to cost of goods sold. One journal moves the amounts out of the exchange-
 * difference and purchase-variance accounts, so the total cost of the shipment equals the cash really paid.
 * It can run once per shipment; later corrections are a normal journal reversal.
 */
final class ShipmentSettlementService
{
    public function __construct(
        private readonly SettlementRepository $repo,
        private readonly PayablesRepository $payables,
        private readonly StockRevaluation $stock,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    /** What settling would do, without writing anything. @return array<string,mixed> */
    public function preview(string $receiptReference): array
    {
        return $this->plan($receiptReference);
    }

    /** @return array<string,mixed> */
    public function settle(string $receiptReference, ?int $userId): array
    {
        return $this->tx->run(function () use ($receiptReference, $userId): array {
            $plan = $this->plan($receiptReference);   // re-checked inside the transaction

            foreach ($plan['lines'] as $line) {
                foreach ($line['revaluations'] as $r) {
                    $this->stock->revalue($line['product_id'], $r['warehouse_id'], $line['lot_no'], $r['amount'], "settlement:{$receiptReference}", "settle:{$receiptReference}:{$line['product_id']}:{$line['lot_no']}:{$r['warehouse_id']}");
                }
            }
            $id = $this->repo->insertSettlement($receiptReference, $plan['fx_difference'], $plan['cleared_variance'], $plan['delta'], $plan['inventory_part'], $plan['cogs_part'], $userId,
                array_map(fn ($l) => ['product_id' => $l['product_id'], 'lot_no' => $l['lot_no'], 'delta' => $l['delta'], 'inventory_part' => $l['inventory_part'], 'cogs_part' => $l['cogs_part']], $plan['lines']));
            $this->events->dispatch(new ShipmentCostSettled($id, $receiptReference, $plan['inventory_part'], $plan['cogs_part'], $plan['fx_difference'], $plan['cleared_variance']));

            return $plan + ['settlement_id' => $id];
        });
    }

    /** @return array<string,mixed> */
    private function plan(string $reference): array
    {
        $status = $this->repo->shipmentStatus($reference) ?? throw new DomainException("{$reference} is not a known shipment.");
        if ($status !== 'received') {
            throw new DomainException("{$reference} must be received into stock first (status: {$status}).");
        }
        if ($this->repo->isSettled($reference)) {
            throw new DomainException("{$reference} has already been settled to its final cost.");
        }
        $invoices = $this->repo->linkedInvoices($reference);
        if ($invoices === []) {
            throw new DomainException("No supplier invoices are recorded for {$reference}.");
        }
        foreach ($invoices as $inv) {
            if ($inv['subtotal']->add($inv['tax'])->sub($inv['paid'])->isPositive()) {
                throw new DomainException("Supplier invoice {$inv['number']} is not fully paid; the final cost is known only after payment.");
            }
        }
        $summary = $this->payables->receiptSummary($reference);
        if (! Fixed::parse($summary['open'])->isZero()) {
            throw new DomainException("GR/IR of {$reference} is not matched (open {$summary['open']}); record the missing invoices or clear the difference first.");
        }

        // FX difference of the payments on the goods-and-cost part (VAT part is not capitalised)
        $fx = Fixed::zero();
        foreach ($invoices as $inv) {
            $total = $inv['subtotal']->add($inv['tax']);
            foreach ($this->repo->allocations($inv['id']) as $a) {
                $cashEx = Fixed::units(Fixed::mulDiv($a['cash_base']->units, $inv['subtotal']->units, $total->units));
                $invEx = Fixed::units(Fixed::mulDiv($a['invoice_base']->units, $inv['subtotal']->units, $total->units));
                $fx = $fx->add($cashEx->sub($invEx));
            }
        }
        $cleared = Fixed::parse($summary['cleared']);
        $delta = $fx->sub($cleared);

        // booked cost and received quantity per (product, lot)
        $groups = [];
        foreach ($this->repo->receiptLines($reference) as $r) {
            $k = $r['product_id'].'|'.$r['lot_no'];
            $groups[$k] ??= ['product_id' => $r['product_id'], 'lot_no' => $r['lot_no'], 'quantity' => Fixed::zero(), 'booked' => Fixed::zero()];
            $groups[$k]['quantity'] = $groups[$k]['quantity']->add($r['quantity']);
            $groups[$k]['booked'] = $groups[$k]['booked']->add($r['total_cost']);
        }
        if ($groups === []) {
            throw new DomainException("No receipt movements found for {$reference}.");
        }
        $deltas = $delta->isZero()
            ? array_fill_keys(array_keys($groups), Fixed::zero())
            : $delta->allocate(array_map(fn ($g) => $g['booked']->units, $groups));

        $lines = [];
        $inventoryTotal = Fixed::zero();
        $cogsTotal = Fixed::zero();
        foreach ($groups as $k => $g) {
            $holdings = $this->repo->lotHoldings($g['product_id'], $g['lot_no']);
            $held = array_reduce($holdings, fn (Fixed $c, $h) => $c->add($h['on_hand']), Fixed::zero());
            $d = $deltas[$k];
            $inStock = $held->gte($g['quantity']) ? $d : Fixed::units(Fixed::mulDiv($d->units, $held->units, $g['quantity']->units));
            $sold = $d->sub($inStock);
            $revals = [];
            if (! $inStock->isZero()) {
                if ($held->isZero()) {
                    throw new DomainException('Internal: stock part without stock.');
                }
                $parts = $inStock->allocate(array_map(fn ($h) => $h['on_hand']->units, $holdings));
                foreach ($holdings as $i => $h) {
                    $revals[] = ['warehouse_id' => $h['warehouse_id'], 'amount' => $parts[$i]];
                }
            }
            $lines[] = [
                'product_id' => $g['product_id'], 'lot_no' => $g['lot_no'], 'received_quantity' => $g['quantity'], 'quantity_in_stock' => $held,
                'booked_cost' => $g['booked'], 'delta' => $d, 'inventory_part' => $inStock, 'cogs_part' => $sold, 'revaluations' => $revals,
            ];
            $inventoryTotal = $inventoryTotal->add($inStock);
            $cogsTotal = $cogsTotal->add($sold);
        }

        return [
            'receipt_reference' => $reference, 'fx_difference' => $fx, 'cleared_variance' => $cleared, 'delta' => $delta,
            'inventory_part' => $inventoryTotal, 'cogs_part' => $cogsTotal, 'lines' => $lines,
            'booked_total' => array_reduce($groups, fn (Fixed $c, $g) => $c->add($g['booked']), Fixed::zero()),
        ];
    }
}
