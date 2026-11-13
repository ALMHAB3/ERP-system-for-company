<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
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
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\ReceivablesService;
use Modules\Sales\Application\SalesOrderService;
use Modules\Sales\Application\SalesReturnService;
use Modules\Sales\Domain\Exceptions\CreditLimitExceeded;
use Modules\Shared\Domain\Fixed;

final class AccountingTest extends ErpTestCase
{
    private int $customer;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.vat_rate' => '0.1500']);
        $this->customer = DB::table('customers')->insertGetId(['name' => 'Al Noor', 'category' => 'retail', 'credit_limit' => 0, 'terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $this->supplier = DB::table('suppliers')->insertGetId(['name' => 'Shenzhen Ltd', 'currency' => 'USD', 'terms_days' => 45, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => null, 'price' => 20 * Fixed::FACTOR]);
    }

    private function tb(): array
    {
        $rows = [];
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            $rows[$r['account_code']] = ['debit' => Fixed::parse($r['debit']), 'credit' => Fixed::parse($r['credit']), 'net' => Fixed::parse($r['debit'])->sub(Fixed::parse($r['credit']))];
        }

        return $rows;
    }

    private function importShipment(): string
    {
        $svc = app(LandedCostService::class);
        $s = $svc->open(new Shipment(null, 'CONT-7', 'USD', Fixed::of(1), 'open', [
            new ShipmentLine(null, $this->product, Fixed::parse('1000'), Fixed::parse('10'), Fixed::parse('10'), Fixed::parse('2000'), Fixed::parse('0.05')),
        ], [
            new CostItem('FREIGHT', Fixed::parse('1000'), CostBasis::Volume, true),
            new CostItem('INSURANCE', Fixed::parse('100'), CostBasis::Value, true),
            new CostItem('CLEARANCE', Fixed::parse('600'), CostBasis::Weight),
        ]));
        $svc->finalize($s->id);
        $svc->receive($s->id, $this->warehouse);

        return 'shipment:CONT-7';   // landed total 12,255
    }

    private function bill(string $kind, string $subtotal, string $ref = 'shipment:CONT-7', string $currency = 'USD', string $rate = '1', string $tax = '0', ?string $number = null): int
    {
        return app(PayablesService::class)->recordInvoice($this->supplier, $number ?? uniqid('INV-'), new DateTimeImmutable('2026-03-01'), $currency, Fixed::parse($rate),
            Fixed::parse($subtotal), Fixed::parse($tax), InvoiceKind::from($kind), $ref);
    }

    public function test_supplier_invoices_clear_goods_received_and_leave_it_at_zero_when_everything_is_billed(): void
    {
        $ref = $this->importShipment();
        $this->assertSame('12255.0000', (string) $this->tb()['2100']['credit']);   // receipt credited GR/IR at landed cost

        $this->bill('goods', '10000');                      // supplier invoice, FOB
        $this->bill('freight', '1000');
        $this->bill('insurance', '100');
        $this->bill('customs', '555');                      // 5% duty on CIF 11,100
        $this->assertSame('600.0000', (string) app(PayablesService::class)->receiptStatus($ref)['open']);   // clearance still to come
        $this->bill('clearance', '600');

        $status = app(PayablesService::class)->receiptStatus($ref);
        $this->assertSame('0.0000', $status['open']);
        $this->assertSame('0.0000', (string) $this->tb()['2100']['net']);          // GR/IR nets to zero
        $this->assertSame('-12255.0000', (string) $this->tb()['2000']['net']);     // we owe 12,255 to suppliers
    }

    public function test_input_vat_is_booked_separately_and_payable_includes_it(): void
    {
        $this->importShipment();
        $this->bill('goods', '10000', tax: '1500');
        $tb = $this->tb();
        $this->assertSame('1500.0000', (string) $tb['1400']['debit']);
        $this->assertSame('11500.0000', (string) $tb['2000']['credit']);
    }

    public function test_foreign_currency_payment_books_the_exchange_difference_and_closes_exactly(): void
    {
        $this->importShipment();
        $invoice = $this->bill('goods', '10000', currency: 'EUR', rate: '4', number: 'EU-1');   // base 40,000
        $svc = app(PayablesService::class);

        $svc->pay($this->supplier, new DateTimeImmutable('2026-04-01'), 'EUR', Fixed::parse('4.1'), 'bank', 'TT-1', [$invoice => Fixed::parse('4000')]);
        $tb = $this->tb();
        $this->assertSame('16000.0000', (string) $tb['2000']['debit']);     // 4,000 x 4.0
        $this->assertSame('400.0000', (string) $tb['5200']['debit']);       // loss: paid at 4.1
        $this->assertSame('16400.0000', (string) $tb['1100']['credit']);

        $svc->pay($this->supplier, new DateTimeImmutable('2026-04-02'), 'EUR', Fixed::parse('3.9'), 'bank', 'TT-2', [$invoice => Fixed::parse('6000')]);
        $tb = $this->tb();
        $this->assertSame('40000.0000', (string) $tb['2000']['debit']);     // AP fully relieved, no rounding residue
        $this->assertSame('0.0000', (string) $tb['2000']['net']);
        $this->assertSame('-200.0000', (string) $tb['5200']['net']);        // 400 loss - 600 gain = 200 net gain
        $this->assertSame(0, (int) DB::table('supplier_invoices')->selectRaw('base_total - base_paid as b')->value('b'));
    }

    public function test_payment_rules_prevent_overpaying_and_cross_supplier_use(): void
    {
        $this->importShipment();
        $invoice = $this->bill('goods', '100');
        $other = DB::table('suppliers')->insertGetId(['name' => 'Other', 'currency' => 'USD', 'terms_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $svc = app(PayablesService::class);

        try {
            $svc->pay($this->supplier, new DateTimeImmutable, 'USD', Fixed::of(1), 'bank', 'x', [$invoice => Fixed::parse('100.0001')]);
            $this->fail('overpayment must be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not exceed its balance', $e->getMessage());
        }
        $this->expectException(DomainException::class);
        $svc->pay($other, new DateTimeImmutable, 'USD', Fixed::of(1), 'bank', 'x', [$invoice => Fixed::parse('10')]);
    }

    public function test_a_supplier_invoice_number_cannot_be_booked_twice(): void
    {
        $this->importShipment();
        $this->bill('goods', '100', number: 'DUP-1');
        $this->expectException(QueryException::class);
        $this->bill('goods', '100', number: 'DUP-1');
    }

    public function test_remaining_gr_ir_is_cleared_to_variance_once(): void
    {
        $ref = $this->importShipment();
        $this->bill('goods', '9990');           // supplier billed 10 less than the stock was valued at
        $this->assertSame('2265.0000', app(PayablesService::class)->receiptStatus($ref)['open']);
        $this->bill('freight', '1000');
        $this->bill('insurance', '100');
        $this->bill('customs', '555');
        $this->bill('clearance', '600');
        $svc = app(PayablesService::class);
        $this->assertSame('10.0000', (string) $svc->clearToVariance($ref, 'rounding settled with supplier'));
        $this->assertSame('0.0000', (string) $this->tb()['2100']['net']);
        $this->assertSame('-10.0000', (string) $this->tb()['5300']['net']);

        $this->expectException(DomainException::class);
        $svc->clearToVariance($ref, 'again');
    }

    private function sell(string $qty, bool $override = false): int
    {
        return app(SalesOrderService::class)->place($this->customer, $this->warehouse, [new OrderLineRequest($this->product, Fixed::parse($qty))], overrideCredit: $override)->id;
    }

    public function test_receipts_settle_invoices_and_ageing_follows_due_dates(): void
    {
        $this->importShipment();
        $sales = app(SalesOrderService::class);
        $o = $this->sell('100');                                    // 100 x 20 = 2,000 + 15% = 2,300
        $sales->ship($o);
        $invoiceId = $sales->invoice($o);
        $this->assertSame('2300.0000', (string) $this->tb()['1200']['net']);

        $recv = app(ReceivablesService::class);
        $this->assertSame('2300.0000', $recv->ageing(new DateTimeImmutable('today'))['buckets']['current']);
        $this->assertSame('2300.0000', $recv->ageing(new DateTimeImmutable('+50 days'))['buckets']['1-30']);   // due in 30, so 20 days late
        $this->assertSame('2300.0000', $recv->ageing(new DateTimeImmutable('+200 days'))['buckets']['90+']);

        $recv->receive($this->customer, new DateTimeImmutable, 'bank', 'TRX-1', [$invoiceId => Fixed::parse('1000')]);
        $this->assertSame('1300.0000', (string) $this->tb()['1200']['net']);
        $this->assertSame('1300.0000', (string) $recv->ageing(new DateTimeImmutable)['buckets']['current']);
        $this->assertSame('1000.0000', (string) $this->tb()['1100']['net']);

        $this->expectException(DomainException::class);
        $recv->receive($this->customer, new DateTimeImmutable, 'bank', 'TRX-2', [$invoiceId => Fixed::parse('1300.0001')]);
    }

    public function test_credit_limit_blocks_orders_and_needs_an_override(): void
    {
        $this->importShipment();
        DB::table('customers')->where('id', $this->customer)->update(['credit_limit' => 3000 * Fixed::FACTOR]);

        $first = $this->sell('100');     // exposure 2,300 (reserved order counts)
        try {
            $this->sell('50');           // +1,150 -> 3,450 > 3,000
            $this->fail('credit limit must block this order');
        } catch (CreditLimitExceeded $e) {
            $this->assertStringContainsString('exceeded', $e->getMessage());
        }
        $this->assertSame(1, DB::table('sales_orders')->count());
        $this->assertSame('900.0000', (string) app(StockLedgerRepository::class)->balance($this->product, $this->warehouse)->available());   // rolled-back reservation

        $this->assertNotNull($this->sell('50', override: true));
        app(SalesOrderService::class)->cancel($first);
    }

    public function test_return_goes_back_to_the_same_lot_at_its_cost_and_credit_note_reverses_revenue(): void
    {
        $this->importShipment();
        $sales = app(SalesOrderService::class);
        $o = $this->sell('100');
        $sales->ship($o);
        $sales->invoice($o);
        $lineId = (int) DB::table('sales_order_lines')->where('sales_order_id', $o)->value('id');
        $before = $this->tb();
        $this->assertSame('1225.5000', (string) $before['5000']['net']);        // 100 x 12.255

        app(SalesReturnService::class)->returnGoods($o, [$lineId => Fixed::parse('40')], 'damaged on arrival');

        $tb = $this->tb();
        $this->assertSame('735.3000', (string) $tb['5000']['net']);             // COGS reversed for 40 x 12.255 = 490.20
        $this->assertSame('1200.0000', (string) $tb['4000']['net']->neg());     // revenue 2,000 - 800 net credited
        $this->assertSame('1380.0000', (string) $tb['1200']['net']);            // AR 2,300 - 920 gross credit
        $lot = app(StockLedgerRepository::class)->lots($this->product, $this->warehouse)[0];
        $this->assertStringStartsWith('SHP-', $lot->lotNo);
        $this->assertSame('940.0000', (string) $lot->onHand);                   // 1000 - 100 + 40
        $this->assertSame([], app(LedgerReconciler::class)->reconcile());

        try {   // 60 shipped and not yet returned: 61 is too many
            app(SalesReturnService::class)->returnGoods($o, [$lineId => Fixed::parse('61')], 'x');
            $this->fail('cannot return more than shipped');
        } catch (DomainException) {
        }
        app(SalesReturnService::class)->returnGoods($o, [$lineId => Fixed::parse('60')], 'rest');
        $this->assertSame('0.0000', (string) $this->tb()['5000']['net']);      // last piece reverses the exact remaining cost
    }

    public function test_a_credit_note_cannot_exceed_the_open_balance(): void
    {
        $this->importShipment();
        $sales = app(SalesOrderService::class);
        $o = $this->sell('10');
        $sales->ship($o);
        $invoiceId = $sales->invoice($o);
        app(ReceivablesService::class)->receive($this->customer, new DateTimeImmutable, 'cash', 'full', [$invoiceId => Fixed::parse('230')]);
        $lineId = (int) DB::table('sales_order_lines')->where('sales_order_id', $o)->value('id');

        $this->expectException(DomainException::class);
        app(SalesReturnService::class)->returnGoods($o, [$lineId => Fixed::parse('1')], 'late return on a paid invoice');
    }
}
