<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Application\AtpService;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Application\LedgerReconciler;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\Exceptions\InvalidStockMove;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Domain\Fixed;

final class LotCostingTest extends ErpTestCase
{
    private function receive(string $lot, string $qty, string $unitCost, ?string $expiry = null, ?int $warehouse = null)
    {
        return app(InventoryLedger::class)->record(new StockMoveData(
            $this->product, $warehouse ?? $this->warehouse, MoveType::Receipt, Fixed::parse($qty), Fixed::parse($unitCost), 'grn',
            lotNo: $lot, expiryDate: $expiry ? new DateTimeImmutable($expiry) : null,
        ));
    }

    private function issue(string $qty, ?string $lot = null)
    {
        return app(InventoryLedger::class)->issue(new StockMoveData($this->product, $this->warehouse, MoveType::Issue, Fixed::parse($qty), null, 'sale', lotNo: $lot));
    }

    public function test_issue_takes_the_earliest_expiry_first_and_costs_each_lot_separately(): void
    {
        $this->receive('LATE', '10', '20', '+300 days');
        $this->receive('SOON', '10', '10', '+30 days');
        $this->receive('NOEXP', '10', '5');

        $issued = $this->issue('15');   // 10 from SOON @10 + 5 from LATE @20

        $this->assertSame(['SOON', 'LATE'], array_map(fn ($m) => $m->lotNo, $issued->moves));
        $this->assertSame('200.0000', (string) $issued->totalCost());   // 100 + 100, not an average
        $balance = app(StockLedgerRepository::class)->balance($this->product, $this->warehouse);
        $this->assertSame('15.0000', (string) $balance->onHand);
        $this->assertSame('150.0000', (string) $balance->value);          // LATE 5 @20 + NOEXP 10 @5
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_expired_lots_are_never_sold_but_can_be_written_off(): void
    {
        $this->receive('OLD', '10', '10', '-1 day');
        $this->receive('FRESH', '5', '10', '+100 days');

        try {
            $this->issue('6');
            $this->fail('expired stock must not be sellable');
        } catch (InsufficientStock $e) {
            $this->assertStringContainsString('available 5.0000', $e->getMessage());
        }
        $this->assertSame('5.0000', (string) $this->issue('5')->quantity());

        $writeOff = app(InventoryLedger::class)->recordMany(new StockMoveData($this->product, $this->warehouse, MoveType::AdjustmentOut, Fixed::parse('10'), null, 'expired', lotNo: 'OLD'));
        $this->assertSame('100.0000', (string) $writeOff[0]->totalCost);
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_a_specific_lot_can_be_requested(): void
    {
        $this->receive('A', '10', '1');
        $this->receive('B', '10', '9');
        $issued = $this->issue('4', 'B');
        $this->assertSame('36.0000', (string) $issued->totalCost());
        $this->assertSame('B', $issued->moves[0]->lotNo);
    }

    public function test_receiving_the_same_lot_with_a_different_expiry_is_refused(): void
    {
        $this->receive('L1', '10', '1', '+60 days');
        $this->expectException(InvalidStockMove::class);
        $this->receive('L1', '5', '1', '+90 days');
    }

    public function test_transfer_moves_lots_with_their_cost_and_expiry(): void
    {
        $branch = $this->makeWarehouse('BR1');
        $this->receive('SOON', '4', '10', '+30 days');
        $this->receive('LATE', '6', '20', '+200 days');

        [$out, $in] = app(InventoryLedger::class)->transfer($this->product, $this->warehouse, $branch, Fixed::parse('7'), 'tr');

        $this->assertSame(['SOON', 'LATE'], array_map(fn ($m) => $m->lotNo, $in));
        $lots = app(StockLedgerRepository::class)->lots($this->product, $branch);
        $this->assertSame('4.0000', (string) $lots[1]->onHand);   // lots sorted by number: LATE, SOON
        $this->assertSame('3.0000', (string) $lots[0]->onHand);
        $this->assertSame('60.0000', (string) $lots[0]->value);
        $this->assertNotNull($lots[1]->expiryDate);
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_reservation_consume_takes_fefo_and_idempotency_covers_every_lot_move(): void
    {
        $this->receive('SOON', '3', '10', '+10 days');
        $this->receive('LATE', '7', '30', '+100 days');
        $atp = app(AtpService::class);
        $r = $atp->reserve($this->product, $this->warehouse, Fixed::parse('5'), 'so');
        $first = $atp->consume($r->id, 'so', 'ship-1');
        $this->assertCount(2, $first->moves);
        $this->assertSame('90.0000', (string) $first->totalCost());       // 3x10 + 2x30

        $again = app(InventoryLedger::class)->recordMany(new StockMoveData(
            $this->product, $this->warehouse, MoveType::Issue, Fixed::parse('5'), null, 'so', idempotencyKey: 'ship-1',
        ));
        $this->assertSame(array_map(fn ($m) => $m->id, $first->moves), array_map(fn ($m) => $m->id, $again));
        $this->assertSame(2, DB::table('stock_moves')->where('type', 'issue')->count());
    }

    public function test_reconciler_detects_a_tampered_lot(): void
    {
        $this->receive('A', '10', '1');
        DB::table('stock_lots')->update(['on_hand' => 99 * Fixed::FACTOR]);
        $drifts = app(LedgerReconciler::class)->reconcile();
        $this->assertStringContainsString('lot A', implode(' ', array_map('strval', $drifts)));
    }
}
