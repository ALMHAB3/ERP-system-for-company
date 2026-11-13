<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Import\Application\LandedCostService;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentLine;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Reporting\Application\PlanningService;
use Modules\Reporting\Application\ReportQueries;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\SalesOrderService;
use Modules\Sales\Application\SalesReturnService;
use Modules\Shared\Domain\Fixed;

final class ReportingTest extends ErpTestCase
{
    private function importAndSell(): void
    {
        config(['erp.vat_rate' => '0.1500']);
        $svc = app(LandedCostService::class);
        $s = $svc->open(new Shipment(null, 'CONT-R', 'USD', Fixed::of(1), 'open', [
            new ShipmentLine(null, $this->product, Fixed::parse('1000'), Fixed::parse('10'), Fixed::parse('10'), Fixed::parse('2000'), Fixed::parse('0.05')),
        ], [
            new CostItem('FREIGHT', Fixed::parse('1000'), CostBasis::Volume, true),
            new CostItem('INSURANCE', Fixed::parse('100'), CostBasis::Value, true),
            new CostItem('CLEARANCE', Fixed::parse('600'), CostBasis::Weight),
        ]));
        $svc->finalize($s->id);
        $svc->receive($s->id, $this->warehouse);                  // landed 12,255 for 1,000 units

        $customer = DB::table('customers')->insertGetId(['name' => 'C', 'category' => 'retail', 'credit_limit' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => null, 'price' => 20 * Fixed::FACTOR]);
        $sales = app(SalesOrderService::class);
        $o = $sales->place($customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse('100'))])->id;
        $sales->ship($o);
        $sales->invoice($o);
        $lineId = (int) DB::table('sales_order_lines')->where('sales_order_id', $o)->value('id');
        app(SalesReturnService::class)->returnGoods($o, [$lineId => Fixed::parse('10')], 'test');
    }

    public function test_shipment_profitability_follows_lots_through_sales_and_returns(): void
    {
        $this->importAndSell();
        $row = app(ReportQueries::class)->shipmentProfitability()[0];

        $this->assertSame('CONT-R', $row['shipment']);
        $this->assertSame('90.0000', $row['sold_qty']);                // 100 sold - 10 returned
        $this->assertSame(9.0, $row['sell_through_pct']);
        $this->assertSame('1800.0000', $row['revenue']);               // 90 x 20
        $this->assertSame('1102.9500', $row['cogs']);                  // 90 x 12.255
        $this->assertSame('697.0500', $row['profit']);
        $this->assertSame(38.7, $row['margin_pct']);
        $this->assertSame('11152.0500', $row['unsold_stock_cost']);    // landed 12,255 - cogs 1,102.95
    }

    public function test_dashboard_and_product_profitability(): void
    {
        $this->importAndSell();
        $d = app(ReportQueries::class)->dashboard(new DateTimeImmutable);

        $this->assertSame('11152.0500', $d['inventory_value']);        // 910 units still in stock at lot cost
        $this->assertSame('1800.0000', $d['revenue_this_month']);      // invoice net 2,000 - credit note net 200
        $this->assertSame(['open' => 0, 'costed' => 0, 'received' => 1], $d['shipments']);
        $this->assertNotNull($d['inventory_turnover']);

        $p = app(ReportQueries::class)->productProfitability(new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'))[0];
        $this->assertSame('SKU-1', $p['sku']);
        $this->assertSame('2000.0000', $p['revenue']);                 // gross of returns: returns appear in the shipment view
    }

    public function test_expiring_and_slow_moving_reports(): void
    {
        $ledger = app(InventoryLedger::class);
        $ledger->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse('10'), Fixed::parse('5'), 'g', lotNo: 'EXP1', expiryDate: new DateTimeImmutable('+20 days')));
        $ledger->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse('10'), Fixed::parse('5'), 'g', lotNo: 'FAR', expiryDate: new DateTimeImmutable('+400 days')));
        $ledger->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse('10'), Fixed::parse('5'), 'g', lotNo: 'GONE', expiryDate: new DateTimeImmutable('-3 days')));

        $q = app(ReportQueries::class);
        $lots = $q->expiring(60, new DateTimeImmutable);
        $this->assertSame(['GONE', 'EXP1'], array_column($lots, 'lot_no'));
        $this->assertTrue($lots[0]['expired']);
        $this->assertSame('50.0000', $lots[1]['value']);
        $this->assertCount(1, $q->slowMoving(30, new DateTimeImmutable));    // never sold = slow
    }

    public function test_reorder_suggestion_uses_forecast_lead_time_and_incoming_stock(): void
    {
        $ledger = app(InventoryLedger::class);
        $ledger->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse('1000'), Fixed::parse('5'), 'seed', occurredAt: new DateTimeImmutable('-9 months')));
        for ($m = 8; $m >= 1; $m--) {                                                   // 100 units a month for 8 months
            $ledger->record(new StockMoveData($this->product, $this->warehouse, MoveType::Issue, Fixed::parse('100'), null, "sale{$m}", occurredAt: new DateTimeImmutable("-{$m} months +2 days")));
        }
        $planning = app(PlanningService::class);

        $this->assertSame(array_fill(0, 8, 100.0), $planning->monthlyDemand($this->product, 12));
        $this->assertSame([], $planning->reorderSuggestions(), '200 on hand covers 45 days of lead time at ~3.3/day');

        DB::table('products')->update(['lead_time_days' => 90]);
        $s = $planning->reorderSuggestions()[0];
        $this->assertSame('SKU-1', $s['sku']);
        $this->assertEqualsWithDelta(3.33, $s['daily_demand'], 0.2);
        $this->assertEqualsWithDelta(200, $s['suggested_quantity'], 40);

        // goods already on the way count towards the position, so the suggestion disappears
        $shipment = DB::table('shipments')->insertGetId(['reference' => 'ON-WAY', 'currency' => 'USD', 'exchange_rate' => 10000, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipment_lines')->insert(['shipment_id' => $shipment, 'product_id' => $this->product, 'quantity' => 500 * Fixed::FACTOR, 'unit_price' => 50000]);
        $this->assertSame([], $planning->reorderSuggestions());
    }
}
