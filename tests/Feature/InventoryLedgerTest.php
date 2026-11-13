<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Application\LedgerReconciler;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\Exceptions\InvalidStockMove;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Domain\Fixed;

final class InventoryLedgerTest extends ErpTestCase
{
    private function receive(string $qty, string $unitCost, ?string $key = null)
    {
        return app(InventoryLedger::class)->record(new StockMoveData(
            $this->product, $this->warehouse, MoveType::Receipt, Fixed::parse($qty), Fixed::parse($unitCost), 'test', idempotencyKey: $key,
        ));
    }

    private function balance()
    {
        return app(StockLedgerRepository::class)->balance($this->product, $this->warehouse);
    }

    public function test_balance_is_the_cumulative_result_of_moves_with_moving_average_cost(): void
    {
        $this->receive('100', '10');   // 100 @ 10 = 1000
        $this->receive('100', '20');   // 200 @ avg 15 = 3000
        $this->assertSame('200.0000', (string) $this->balance()->onHand);
        $this->assertSame('15.0000', (string) $this->balance()->averageCost());

        $issue = app(InventoryLedger::class)->record(new StockMoveData(
            $this->product, $this->warehouse, MoveType::Issue, Fixed::parse('50'), null, 'sale',
        ));
        $this->assertSame('15.0000', (string) $issue->unitCost);
        $this->assertSame('750.0000', (string) $issue->totalCost);
        $this->assertSame('150.0000', (string) $issue->balanceQtyAfter);
        $this->assertSame('2250.0000', (string) $issue->balanceValueAfter);
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_issuing_the_last_units_empties_value_exactly(): void
    {
        $this->receive('3', '3.3333');   // value 9.9999
        $ledger = app(InventoryLedger::class);
        foreach (['1', '1', '1'] as $q) {
            $ledger->record(new StockMoveData($this->product, $this->warehouse, MoveType::Issue, Fixed::parse($q), null, 'sale'));
        }
        $this->assertSame('0.0000', (string) $this->balance()->onHand);
        $this->assertSame('0.0000', (string) $this->balance()->value);
    }

    public function test_cannot_issue_more_than_is_available(): void
    {
        $this->receive('10', '5');
        $this->expectException(InsufficientStock::class);
        app(InventoryLedger::class)->record(new StockMoveData($this->product, $this->warehouse, MoveType::Issue, Fixed::parse('10.0001'), null, 'sale'));
    }

    public function test_failed_issue_changes_nothing(): void
    {
        $this->receive('10', '5');
        try {
            app(InventoryLedger::class)->record(new StockMoveData($this->product, $this->warehouse, MoveType::Issue, Fixed::parse('11'), null, 'sale'));
        } catch (InsufficientStock) {
        }
        $this->assertSame(1, DB::table('stock_moves')->count());
        $this->assertSame('10.0000', (string) $this->balance()->onHand);
    }

    public function test_quantity_must_be_positive_and_inbound_needs_a_cost(): void
    {
        $this->expectException(InvalidStockMove::class);
        app(InventoryLedger::class)->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse('1'), null, 'no cost'));
    }

    public function test_same_idempotency_key_posts_once(): void
    {
        $first = $this->receive('10', '5', 'grn-1');
        $second = $this->receive('10', '5', 'grn-1');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('10.0000', (string) $this->balance()->onHand);
        $this->assertSame(1, DB::table('stock_moves')->count());
    }

    public function test_ledger_rows_cannot_be_updated_or_deleted_even_with_raw_sql(): void
    {
        $this->receive('10', '5');
        try {
            DB::transaction(fn () => DB::table('stock_moves')->update(['quantity' => 999999])); // savepoint keeps pgsql usable
            $this->fail('update must be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('stock_moves')->delete());
            $this->fail('delete must be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_reconciler_detects_a_hand_edited_balance(): void
    {
        $this->receive('10', '5');
        DB::table('stock_balances')->update(['on_hand' => 15 * Fixed::FACTOR]); // someone "fixes" the number
        $drifts = app(LedgerReconciler::class)->reconcile();
        $this->assertNotEmpty($drifts);
        $this->assertStringContainsString('on_hand', (string) $drifts[0]);
    }

    public function test_transfer_moves_stock_at_cost_between_warehouses(): void
    {
        $this->receive('10', '7');
        $branch = $this->makeWarehouse('BR1');
        [$out, $in] = app(InventoryLedger::class)->transfer($this->product, $this->warehouse, $branch, Fixed::parse('4'), 'tr-1');

        $this->assertSame($out[0]->totalCost->units, $in[0]->totalCost->units);
        $this->assertSame('6.0000', (string) $this->balance()->onHand);
        $this->assertSame('4.0000', (string) app(StockLedgerRepository::class)->balance($this->product, $branch)->onHand);
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }
}
