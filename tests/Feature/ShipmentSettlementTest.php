<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\JournalRepository;
use Modules\Import\Application\LandedCostService;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentLine;
use Modules\Inventory\Application\LedgerReconciler;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Procurement\Application\PayablesService;
use Modules\Procurement\Application\ShipmentSettlementService;
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Reporting\Application\ReportQueries;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\SalesOrderService;
use Modules\Shared\Domain\Fixed;

/**
 * Shipment: 1,000 units x 10 USD booked at 4.0 = 40,000 + freight 1,000 LYD  => stock valued 41,000 (41.00 a unit).
 * The dollars are really paid at 4.1, so the true cost is 42,000 (42.00 a unit).
 */
final class ShipmentSettlementTest extends ErpTestCase
{
    private int $supplier;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.vat_rate' => '0.0000']);
        $this->supplier = DB::table('suppliers')->insertGetId(['name' => 'Shenzhen', 'currency' => 'USD', 'terms_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->customer = DB::table('customers')->insertGetId(['name' => 'Retail', 'category' => 'retail', 'credit_limit' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => 'retail', 'price' => 100 * Fixed::FACTOR]);
    }

    private function tb(): array
    {
        $rows = [];
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            $rows[$r['account_code']] = Fixed::parse($r['debit'])->sub(Fixed::parse($r['credit']));   // net debit
        }

        return $rows;
    }

    private function receivedShipment(): string
    {
        $svc = app(LandedCostService::class);
        $s = $svc->open(new Shipment(null, 'CONT-F', 'USD', Fixed::parse('4'), 'open', [
            new ShipmentLine(null, $this->product, Fixed::parse('1000'), Fixed::parse('10'), Fixed::parse('1'), Fixed::parse('1'), Fixed::zero()),
        ], [new CostItem('FREIGHT', Fixed::parse('1000'), CostBasis::Value, true)]));
        $svc->finalize($s->id);
        $svc->receive($s->id, $this->warehouse);

        return 'shipment:CONT-F';
    }

    private function invoice(string $kind, string $subtotal, string $currency, string $rate, string $tax = '0'): int
    {
        return app(PayablesService::class)->recordInvoice($this->supplier, uniqid('I'), new DateTimeImmutable('2026-03-01'), $currency, Fixed::parse($rate), Fixed::parse($subtotal), Fixed::parse($tax), InvoiceKind::from($kind), 'shipment:CONT-F');
    }

    private function pay(int $invoice, string $amount, string $currency, string $rate): void
    {
        app(PayablesService::class)->pay($this->supplier, new DateTimeImmutable('2026-04-01'), $currency, Fixed::parse($rate), 'bank', uniqid('P'), [$invoice => Fixed::parse($amount)]);
    }

    private function sell(string $qty): void
    {
        $sales = app(SalesOrderService::class);
        $o = $sales->place($this->customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse($qty))])->id;
        $sales->ship($o);
        $sales->invoice($o);
    }

    public function test_final_cost_moves_the_exchange_loss_into_stock_and_cogs_exactly(): void
    {
        $ref = $this->receivedShipment();
        $this->sell('100');                                        // sold at the estimated 41.00: COGS 4,100
        $goods = $this->invoice('goods', '10000', 'USD', '4');
        $freight = $this->invoice('freight', '1000', 'LYD', '1');
        $this->pay($goods, '10000', 'USD', '4.1');                 // 41,000 paid for 40,000 booked: 1,000 exchange loss
        $this->pay($freight, '1000', 'LYD', '1');
        $this->assertSame('1000.0000', (string) $this->tb()['5200']);

        $service = app(ShipmentSettlementService::class);
        $preview = $service->preview($ref);
        $this->assertSame('1000.0000', (string) $preview['delta']);
        $this->assertSame('900.0000', (string) $preview['inventory_part']);
        $this->assertSame('100.0000', (string) $preview['cogs_part']);
        $this->assertSame(0, DB::table('shipment_cost_settlements')->count(), 'preview writes nothing');

        $service->settle($ref, null);

        $lot = app(StockLedgerRepository::class)->lots($this->product, $this->warehouse)[0];
        $this->assertSame('900.0000', (string) $lot->onHand);
        $this->assertSame('37800.0000', (string) $lot->value);            // 900 x 42.00: the real cost
        $tb = $this->tb();
        $this->assertSame('0.0000', (string) $tb['5200']);                // the exchange loss left the P&L account
        $this->assertSame('37800.0000', (string) $tb['1300']);            // inventory = 900 units at the final cost
        $this->assertSame('4200.0000', (string) $tb['5000']);             // 100 sold x 42.00
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());

        $row = app(ReportQueries::class)->shipmentProfitability()[0];     // revenue 10,000
        $this->assertSame('42000.0000', $row['landed_cost']);
        $this->assertSame('4200.0000', $row['cogs']);
        $this->assertSame('5800.0000', $row['profit']);
        $this->assertSame('37800.0000', $row['unsold_stock_cost']);
        $this->assertTrue($row['final_cost']);
    }

    public function test_it_runs_once_and_only_when_everything_is_paid_and_matched(): void
    {
        $ref = $this->receivedShipment();
        $service = app(ShipmentSettlementService::class);
        $refuse = function (string $why) use ($service, $ref) {
            try {
                $service->settle($ref, null);
                $this->fail("must refuse: {$why}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        };

        $refuse('No supplier invoices');
        $goods = $this->invoice('goods', '10000', 'USD', '4');
        $freight = $this->invoice('freight', '1000', 'LYD', '1');
        $refuse('not fully paid');
        $this->pay($goods, '10000', 'USD', '4.1');
        $this->pay($freight, '500', 'LYD', '1');
        $refuse('not fully paid');
        $this->pay($freight, '500', 'LYD', '1');

        $service->settle($ref, null);
        $refuse('already been settled');
        $this->assertSame(1, DB::table('shipment_cost_settlements')->count());

        try {
            $service->preview('shipment:NOPE');
            $this->fail('unknown shipment');
        } catch (DomainException) {
        }
    }

    public function test_unmatched_goods_received_blocks_settlement(): void
    {
        $ref = $this->receivedShipment();
        $goods = $this->invoice('goods', '10000', 'USD', '4');     // freight never invoiced: GR/IR still open 1,000
        $this->pay($goods, '10000', 'USD', '4.1');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/GR\/IR .* not matched/');
        app(ShipmentSettlementService::class)->settle($ref, null);
    }

    public function test_vat_part_of_the_exchange_difference_stays_in_the_exchange_account(): void
    {
        $ref = $this->receivedShipment();
        $goods = $this->invoice('goods', '10000', 'USD', '4', '1000');     // 10% VAT: total 11,000 USD
        $freight = $this->invoice('freight', '1000', 'LYD', '1');
        $this->pay($goods, '11000', 'USD', '4.1');                          // exchange loss 1,100 in total: 1,000 on goods + 100 on VAT
        $this->pay($freight, '1000', 'LYD', '1');
        $this->assertSame('1100.0000', (string) $this->tb()['5200']);

        app(ShipmentSettlementService::class)->settle($ref, null);

        $this->assertSame('100.0000', (string) $this->tb()['5200']);       // VAT part is not part of the goods' cost
        $this->assertSame('42000.0000', (string) $this->tb()['1300']);       // inventory at the final cost: 40,000 + 1,000 + 1,000
    }

    public function test_cleared_grir_variance_is_taken_into_the_final_cost_too(): void
    {
        $ref = $this->receivedShipment();
        $goods = $this->invoice('goods', '9990', 'USD', '4');              // supplier billed 10 USD (40 LYD) less than booked
        $freight = $this->invoice('freight', '1000', 'LYD', '1');
        $this->pay($goods, '9990', 'USD', '4');                              // paid at the booked rate: no exchange difference
        $this->pay($freight, '1000', 'LYD', '1');
        app(PayablesService::class)->clearToVariance($ref, 'supplier gave a discount');   // 40 LYD receipts above invoices -> purchase variance credit
        $this->assertSame('-40.0000', (string) $this->tb()['5300']);

        $plan = app(ShipmentSettlementService::class)->settle($ref, null);

        $this->assertSame('-40.0000', (string) $plan['delta']);            // stock was overvalued by 40: true cost 40,960
        $this->assertSame('0.0000', (string) $this->tb()['5300']);
        $lot = app(StockLedgerRepository::class)->lots($this->product, $this->warehouse)[0];
        $this->assertSame('40960.0000', (string) $lot->value);
        $this->assertSame('40960.0000', (string) $this->tb()['1300']);
    }
}
