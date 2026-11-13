<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\JournalService;
use Modules\Finance\Domain\Exceptions\UnbalancedJournalEntry;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\JournalRepository;
use Modules\Import\Application\LandedCostService;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentLine;
use Modules\Inventory\Application\LedgerReconciler;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\SalesOrderService;
use Modules\Sales\Domain\Exceptions\PricingApprovalRequired;
use Modules\Shared\Domain\Fixed;

/** End-to-end: costed import -> stock -> priced order -> ATP -> shipment -> invoice -> balanced books. */
final class ImportToSalesFlowTest extends ErpTestCase
{
    private int $productB;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.vat_rate' => '0.1500']);
        $this->productB = $this->makeProduct('SKU-B');
        $this->customer = DB::table('customers')->insertGetId([
            'name' => 'Al Noor Trading', 'category' => 'wholesale', 'credit_limit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('price_list_items')->insert([
            ['product_id' => $this->product, 'category' => 'wholesale', 'price' => 20 * Fixed::FACTOR],
            ['product_id' => $this->productB, 'category' => null, 'price' => 80 * Fixed::FACTOR],
        ]);
    }

    private function importAndReceive(): int
    {
        $service = app(LandedCostService::class);
        $shipment = $service->open(new Shipment(null, 'CONT-001', 'USD', Fixed::of(1), 'open', [
            new ShipmentLine(null, $this->product, Fixed::parse('1000'), Fixed::parse('10'), Fixed::parse('10'), Fixed::parse('2000'), Fixed::parse('0.05')),
            new ShipmentLine(null, $this->productB, Fixed::parse('500'), Fixed::parse('40'), Fixed::parse('20'), Fixed::parse('1000'), Fixed::parse('0.15')),
        ], [
            new CostItem('FREIGHT', Fixed::parse('3000'), CostBasis::Volume, true),
            new CostItem('INSURANCE', Fixed::parse('300'), CostBasis::Value, true),
            new CostItem('CLEARANCE', Fixed::parse('900'), CostBasis::Weight),
        ]));
        $service->finalize($shipment->id);
        $service->receive($shipment->id, $this->warehouse);

        return $shipment->id;
    }

    private function trialBalance(): array
    {
        $rows = [];
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            $rows[$r['account_code']] = $r;
        }

        return $rows;
    }

    public function test_landed_cost_flows_into_inventory_value_and_the_books(): void
    {
        $this->importAndReceive();

        $balance = app(StockLedgerRepository::class)->balance($this->product, $this->warehouse);
        $this->assertSame('12.2550', (string) $balance->averageCost());          // landed unit cost, not the 10 invoice price
        $this->assertSame('12255.0000', (string) $balance->value);

        $tb = $this->trialBalance();
        $this->assertSame('38085.0000', $tb['1300']['debit']);                    // Dr Inventory at landed cost
        $this->assertSame('38085.0000', $tb['2100']['credit']);                   // Cr GR/IR
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_receiving_twice_is_idempotent_and_costing_twice_is_refused(): void
    {
        $id = $this->importAndReceive();
        $this->expectException(DomainException::class);
        app(LandedCostService::class)->finalize($id);
    }

    public function test_full_sales_cycle_posts_revenue_vat_and_cogs_at_landed_cost(): void
    {
        $this->importAndReceive();
        $sales = app(SalesOrderService::class);

        $order = $sales->place($this->customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse('100'))]);
        $line = $order->lines[0];
        $this->assertSame('20.0000', (string) $line->listPrice);
        $this->assertSame('0.0300', (string) $line->discountRate);                // wholesale quantity break at 100
        $this->assertSame('19.4000', (string) $line->unitPrice);

        $this->assertSame('900.0000', (string) app(StockLedgerRepository::class)->balance($this->product, $this->warehouse)->available());

        $shipped = $sales->ship($order->id);
        $this->assertSame('1225.5000', (string) $shipped->lines[0]->costOfGoods);   // 100 x 12.255

        $sales->invoice($order->id);
        $tb = $this->trialBalance();
        $this->assertSame('2231.0000', $tb['1200']['debit']);    // 1940 net + 291 VAT
        $this->assertSame('1940.0000', $tb['4000']['credit']);
        $this->assertSame('291.0000', $tb['2200']['credit']);
        $this->assertSame('1225.5000', $tb['5000']['debit']);

        $debits = $credits = 0;
        foreach ($tb as $row) {
            $debits += Fixed::parse($row['debit'])->units;
            $credits += Fixed::parse($row['credit'])->units;
        }
        $this->assertSame($debits, $credits, 'the books must balance');
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());
    }

    public function test_an_order_for_unavailable_stock_is_rejected_and_leaves_nothing_reserved(): void
    {
        $this->importAndReceive();
        $sales = app(SalesOrderService::class);

        try {
            $sales->place($this->customer, $this->warehouse, [
                new OrderLineRequest($this->product, Fixed::parse('50')),
                new OrderLineRequest($this->productB, Fixed::parse('501')),     // only 500 exist
            ]);
            $this->fail('expected InsufficientStock');
        } catch (InsufficientStock) {
        }

        $this->assertSame(0, DB::table('sales_orders')->count());
        $this->assertSame(0, DB::table('stock_reservations')->count());          // the first line's reservation rolled back too
        $this->assertSame('1000.0000', (string) app(StockLedgerRepository::class)->balance($this->product, $this->warehouse)->available());
    }

    public function test_cancelling_an_order_releases_the_stock(): void
    {
        $this->importAndReceive();
        $sales = app(SalesOrderService::class);
        $order = $sales->place($this->customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse('10'))]);
        $sales->cancel($order->id);

        $this->assertSame('1000.0000', (string) app(StockLedgerRepository::class)->balance($this->product, $this->warehouse)->available());
    }

    public function test_selling_below_the_minimum_margin_needs_approval(): void
    {
        $this->importAndReceive();
        DB::table('price_list_items')->where('product_id', $this->product)->update(['price' => 12 * Fixed::FACTOR]); // cost is 12.255

        $this->expectException(PricingApprovalRequired::class);
        app(SalesOrderService::class)->place($this->customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse('1'))]);
    }

    public function test_contract_price_beats_the_price_list(): void
    {
        $this->importAndReceive();
        DB::table('contract_prices')->insert(['customer_id' => $this->customer, 'product_id' => $this->product, 'price' => 18 * Fixed::FACTOR]);

        $order = app(SalesOrderService::class)->place($this->customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse('1'))]);
        $this->assertSame('18.0000', (string) $order->lines[0]->listPrice);
    }

    public function test_journals_must_balance_and_are_immutable_and_idempotent(): void
    {
        $journal = app(JournalService::class);
        $entry = fn (string $debit, string $credit) => new JournalEntryData('manual', [
            JournalLineData::debit('1100', Fixed::parse($debit)), JournalLineData::credit('4000', Fixed::parse($credit)),
        ], 'manual', 'm-1', 'manual');

        try {
            $journal->post($entry('100', '99.9999'));
            $this->fail('unbalanced entry must be rejected');
        } catch (UnbalancedJournalEntry) {
        }

        $id = $journal->post($entry('100', '100'));
        $this->assertSame($id, $journal->post($entry('100', '100')), 'same source+rule posts once');

        $reversal = $journal->reverse($id, 'entered twice');
        $this->assertNotSame($id, $reversal);
        $tb = $this->trialBalance();
        $this->assertSame($tb['1100']['debit'], $tb['1100']['credit']);        // reversal nets the account to zero

        $this->expectException(QueryException::class);
        DB::table('journal_lines')->update(['debit' => 1]);
    }
}
