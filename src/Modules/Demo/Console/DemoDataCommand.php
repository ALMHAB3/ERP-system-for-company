<?php

declare(strict_types=1);

namespace Modules\Demo\Console;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\CashAccounts;
use Modules\Finance\Application\ExchangeRates;
use Modules\Finance\Application\JournalService;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Hr\Application\ContractService;
use Modules\Hr\Application\OrgStructureService;
use Modules\Hr\Application\PayrollService;
use Modules\Identity\Domain\AccessRepository;
use Modules\Import\Application\LandedCostService;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\LandedCostCalculator;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentLine;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Procurement\Application\PayablesService;
use Modules\Procurement\Application\PurchaseOrderService;
use Modules\Procurement\Application\ShipmentSettlementService;
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Procurement\Domain\PurchaseOrderLine;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\PriceSuggestionService;
use Modules\Sales\Application\ReceivablesService;
use Modules\Sales\Application\SalesOrderService;
use Modules\Sales\Application\SalesReturnService;
use Modules\Sales\Domain\CustomerCategory;
use Modules\Sales\Domain\PaymentMethod;
use Modules\Sales\Domain\PaymentTerms;
use Modules\Shared\Domain\Fixed;
use Throwable;

/**
 * Loads a believable company history so every screen can be tried before real data goes in.
 * The history is produced by running the REAL services day by day with the clock set to each past date, so the books,
 * stock ledger, audit trail, ageing and charts are all consistent with each other. Refuses to run on a non-empty system.
 */
final class DemoDataCommand extends Command
{
    protected $signature = 'erp:demo-data {--months=14 : months of history} {--with-users : also create one demo user per role}';

    protected $description = 'Load sample data (a full history through the real services) for testing. Only on an empty system.';

    public const DEMO_PASSWORD = 'Demo-Passw0rd-123';

    /** @var array<string,list<callable>> events scheduled per date */
    private array $queue = [];

    private int $failedOrders = 0;

    public function handle(CashAccounts $cash, ExchangeRates $rates): int
    {
        if (DB::table('products')->exists() || DB::table('stock_moves')->exists() || DB::table('suppliers')->exists()) {
            $this->error('The system already has data. Demo data is only for an empty system (use a fresh database).');

            return self::FAILURE;
        }
        $months = max(3, min(36, (int) $this->option('months')));
        mt_srand(2026);   // same story every time

        try {
            $this->simulate($months, $cash, $rates);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->hr();
        if ($this->option('with-users')) {
            $this->users();
        }
        DB::table('system_flags')->updateOrInsert(['name' => 'demo_data'], ['value' => now()->toDateString(), 'created_at' => now()]);
        $this->newLine();
        $this->info('Demo data loaded. The screens now show '.$months.' months of history.');
        $this->warn('This is TEST data (fake names, rates and amounts). Start from an empty database before entering real data.');

        return self::SUCCESS;
    }

    private function at(CarbonImmutable $d): void
    {
        $d = $d->setTime(10, 0);
        Carbon::setTestNow($d);
        CarbonImmutable::setTestNow($d);
    }

    private function later(CarbonImmutable $day, callable $fn): void
    {
        $this->queue[$day->toDateString()][] = $fn;
    }

    private function simulate(int $months, CashAccounts $cash, ExchangeRates $rates): void
    {
        $today = CarbonImmutable::today();
        $start = $today->subMonths($months - 1)->startOfMonth()->addDays(1);
        $this->at($start);

        // ---- master data -------------------------------------------------------------------------------------------
        $now = now();
        foreach ([['MAIN', 'Main warehouse - المستودع الرئيسي', 'main'], ['BR1', 'Benghazi branch - فرع بنغازي', 'branch']] as [$c, $n, $t]) {
            DB::table('warehouses')->insert(['code' => $c, 'name' => $n, 'type' => $t, 'created_at' => $now, 'updated_at' => $now]);
        }
        $products = [];   // sku => [name, usd price, duty %, shelf months, lead days]
        foreach ([
            ['MEMB-APP4', 'APP bitumen membrane 4mm roll - رول عازل بيتومين APP 4 ملم', 18.5, 5, 0, 60, 'ROLL'], ['MEMB-SBS3', 'SBS bitumen membrane 3mm roll - رول عازل SBS 3 ملم', 15.2, 5, 0, 60, 'ROLL'],
            ['PU-COAT-20', 'Polyurethane roof coating 20L - عازل بولي يوريثان سائل 20 لتر', 96, 5, 12, 55, 'PAIL'], ['CEM-WP-25', 'Cementitious waterproofing 25kg - عازل أسمنتي 25 كجم', 13.8, 5, 12, 45, 'BAG'],
            ['PRIMER-18', 'Bitumen primer 18L - برايمر بيتومين 18 لتر', 38, 5, 18, 50, 'PAIL'], ['XPS-50', 'XPS insulation board 50mm - ألواح عزل XPS سماكة 50 ملم', 6.6, 5, 0, 55, 'BOARD'],
            ['EPS-30', 'EPS insulation board 30mm - ألواح فلين EPS 30 ملم', 3.1, 5, 0, 45, 'BOARD'], ['GWOOL-50', 'Glass wool roll 50mm - صوف زجاجي رول 50 ملم', 22.5, 5, 0, 55, 'ROLL'],
            ['GEOTEX-200', 'Geotextile fabric roll 200g - قماش جيوتكستايل 200 جم', 41, 5, 0, 50, 'ROLL'], ['SEAL-310', 'Silicone sealant carton of 24 - سيليكون مانع تسرب كرتون', 31, 10, 12, 50, 'CTN'],
            ['TILEADH-25', 'Tile adhesive 25kg - لاصق بلاط 25 كجم', 4.1, 5, 12, 45, 'BAG'], ['JOINT-TAPE', 'Expansion joint tape roll - شريط فواصل تمدد', 9.4, 5, 0, 50, 'ROLL'],
        ] as [$sku, $name, $usd, $duty, $shelf, $lead, $uom]) {
            $id = DB::table('products')->insertGetId(['sku' => $sku, 'name' => $name, 'base_uom' => $uom, 'lead_time_days' => $lead, 'cover_days' => 30, 'has_expiry' => $shelf > 0, 'created_at' => $now, 'updated_at' => $now]);
            $products[$sku] = ['id' => $id, 'usd' => $usd, 'duty' => $duty, 'shelf' => $shelf];
        }
        $supplier = fn (string $name, string $cur, string $country) => DB::table('suppliers')->insertGetId(['name' => $name, 'country' => $country, 'currency' => $cur, 'terms_days' => 30, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $sup = ['USD' => $supplier('Shandong Waterproof Materials Co.', 'USD', 'CN'), 'CNY' => $supplier('Foshan Building Materials Export', 'CNY', 'CN'), 'EUR' => $supplier('Hamburg Bauchemie GmbH', 'EUR', 'DE'),
            'LYD' => $supplier('شركة الخدمات اللوجستية الليبية', 'LYD', 'LY'), 'CUSTOMS' => $supplier('مصلحة الجمارك', 'LYD', 'LY')];
        $customers = [];
        foreach ([['شركة البناء الحديث للمقاولات', 'wholesale', 90000, 45], ['مؤسسة الأفق للإنشاءات', 'wholesale', 60000, 30], ['مقاولات الصقر العامة', 'wholesale', 40000, 30],
            ['مواد البناء السريع', 'retail', 12000, 15], ['محل الأمين للدهانات والعوازل', 'retail', 6000, 7], ['مشروع فيلا سكنية (مستهلك)', 'direct', 4000, 7]] as [$name, $cat, $limit, $terms]) {
            $customers[] = DB::table('customers')->insertGetId(['name' => $name, 'category' => $cat, 'credit_limit' => $limit * Fixed::FACTOR, 'terms_days' => $terms, 'created_at' => $now, 'updated_at' => $now]);
        }
        $walkIn = (int) DB::table('customers')->where('is_walk_in', true)->value('id');
        $banks = [$cash->create('Commercial Bank - المصرف التجاري', 'LY83 0010 0000 1234', 'LYD'), $cash->create('Sahara Bank - مصرف الصحاري', 'LY47 0020 0000 9876', 'LYD')];
        $bankGl = array_map(fn ($id) => $cash->resolve('transfer', $id), $banks);
        $tillGl = $cash->resolve('cash', null);

        // Opening capital (a manual journal: Dr bank and till / Cr owner capital)
        app(JournalService::class)->post(new JournalEntryData('Opening capital - رأس المال الافتتاحي', [
            JournalLineData::debit($bankGl[0], Fixed::of(3_500_000)), JournalLineData::debit($bankGl[1], Fixed::of(1_500_000)), JournalLineData::debit($tillGl, Fixed::of(100_000)),
            JournalLineData::credit('3000', Fixed::of(5_100_000)),
        ], 'manual', 'opening', 'opening_capital'));

        // ---- the timeline ---------------------------------------------------------------------------------------------
        $rateBase = ['USD' => 4.82, 'EUR' => 5.21, 'CNY' => 0.668];
        $rateNow = $rateBase;
        $shipmentMonths = array_values(array_filter([0, 3, 6, 9, 12, 15, 18, 21, 24, 27, 30, 33], fn ($m) => $m < $months));
        $shipmentCount = 0;
        $stock = app(StockLedgerRepository::class);
        $sales = app(SalesOrderService::class);
        $day = $start;
        $end = $today;
        $seasonal = [1 => 1.0, 2 => 1.0, 3 => 1.4, 4 => 1.2, 5 => 0.9, 6 => 0.9, 7 => 1.0, 8 => 1.0, 9 => 1.0, 10 => 1.1, 11 => 1.0, 12 => 1.2];

        while ($day <= $end) {
            $this->at($day);
            // exchange rates drift month by month and are recorded on the 1st-2nd
            if ($day->day === 2 || $day->equalTo($start)) {
                foreach ($rateNow as $cur => $r) {
                    $rateNow[$cur] = round($rateBase[$cur] * (1 + (mt_rand(-150, 250) / 10000) + ($day->diffInMonths($start) * 0.002)), 4);
                    $rates->set($cur, Fixed::parse(number_format($rateNow[$cur], 4, '.', '')), $day, null);
                }
            }
            // an import shipment is ordered; it arrives ~12 days later
            $monthIndex = (int) $start->diffInMonths($day);
            if ($day->day === 3 && in_array($monthIndex, $shipmentMonths, true) && $day->diffInDays($end) > 12) {
                $n = $shipmentCount++;
                $arrive = $day->addDays(12);
                $this->later($arrive, fn () => $this->shipment($n, $products, $sup, $banks, $bankGl, $tillGl, $rateNow, $today));
            }
            if ($day->day === 20 && $monthIndex > 0 && $monthIndex % 4 === 0) {   // a local purchase every 4 months
                $this->later($day, fn () => $this->localPurchase($products, $monthIndex));
            }

            // customers buy (not before the first stock has arrived and been priced)
            if (DB::table('price_list_items')->exists() && $day->dayOfWeek !== 5) {   // Friday off
                $orders = (int) floor((1.4 * ($seasonal[$day->month] ?? 1)) + mt_rand(0, 100) / 100 * 2);
                for ($i = 0; $i < $orders; $i++) {
                    $this->placeOrder($day, $customers, $walkIn, $bankGl, $tillGl, $banks, $sales, $stock);
                }
            }

            foreach ($this->queue[$day->toDateString()] ?? [] as $fn) {
                $this->at($day);
                try {
                    $fn();
                } catch (Throwable $e) {
                    $this->failedOrders++;   // e.g. stock ran out, credit note larger than the open balance: realistic, skip
                }
            }
            unset($this->queue[$day->toDateString()]);
            $day = $day->addDay();
        }
        // anything scheduled in the future stays unexecuted (open invoices, unpaid bills)
        $this->info('Orders / actions skipped by business rules: '.$this->failedOrders);
        $this->info(sprintf('Shipments: %d · sales orders: %d · invoices: %d', DB::table('shipments')->count(), DB::table('sales_orders')->count(), DB::table('sales_invoices')->count()));
    }

    /** One import shipment: ordered, costed, received, billed by suppliers/forwarder/customs, paid, and (when old enough) settled to final cost. */
    private function shipment(int $n, array $products, array $sup, array $banks, array $bankGl, string $tillGl, array $rateNow, CarbonImmutable $today): void
    {
        $arrive = CarbonImmutable::now();
        $currency = ['USD', 'CNY', 'EUR'][$n % 3];
        $factor = ['USD' => 1.0, 'CNY' => 7.2, 'EUR' => 0.92][$currency];   // price of goods expressed in the supplier's currency
        $skus = array_keys($products);
        $chosen = array_slice($skus, ($n * 3) % count($skus), 6);
        if (count($chosen) < 6) {
            $chosen = array_merge($chosen, array_slice($skus, 0, 6 - count($chosen)));
        }
        $ref = sprintf('CONT-%s-%02d', $arrive->format('ym'), $n + 1);
        $rate = Fixed::parse(number_format($rateNow[$currency], 4, '.', ''));

        $lines = [];
        $goodsForeign = Fixed::zero();
        foreach ($chosen as $sku) {
            $qty = mt_rand(3, 10) * 100;
            $unit = Fixed::parse(number_format($products[$sku]['usd'] * $factor, 4, '.', ''));
            $expiry = $products[$sku]['shelf'] > 0 ? $arrive->addMonths($products[$sku]['shelf']) : null;   // boards and rolls do not expire
            if ($sku === 'SEAL-310' && $n === 0) {
                $expiry = CarbonImmutable::today()->subDays(4);          // an expired lot (visible in expiry reports)
            }
            if ($sku === 'PU-COAT-20' && $arrive->diffInDays($today) < 60) {
                $expiry = CarbonImmutable::today()->addDays(35);          // a lot about to expire
            }
            $lines[] = new ShipmentLine(null, $products[$sku]['id'], Fixed::of($qty), $unit, Fixed::parse(number_format($qty * 0.02, 4, '.', '')), Fixed::of($qty * 18), Fixed::parse(number_format($products[$sku]['duty'] / 100, 4, '.', '')),
                $ref.'-'.$sku, $expiry === null ? null : new DateTimeImmutable($expiry->toDateString()));
            $goodsForeign = $goodsForeign->add(Fixed::of($qty)->mul($unit));
        }
        $goodsBase = $goodsForeign->mul($rate);
        $freight = Fixed::of(mt_rand(60, 140) * 100);
        $insurance = $goodsBase->mul(Fixed::parse('0.004'));
        $clearance = Fixed::of(mt_rand(20, 40) * 100);

        $svc = app(LandedCostService::class);
        $shipment = $svc->open(new Shipment(null, $ref, $currency, $rate, 'open', $lines, [
            new CostItem('FREIGHT', $freight, CostBasis::Volume, true), new CostItem('INSURANCE', $insurance, CostBasis::Value, true), new CostItem('CLEARANCE', $clearance, CostBasis::Weight),
        ]));
        $result = $svc->finalize($shipment->id);
        $svc->receive($shipment->id, (int) DB::table('warehouses')->where('code', 'MAIN')->value('id'));

        // sell prices: cost + margin per category, rounded UP to 5 dinar, applied as the sales team would
        $pricing = app(PriceSuggestionService::class);
        foreach (CustomerCategory::cases() as $category) {
            $suggested = $pricing->suggest($category, Fixed::parse((string) config('erp.pricing.margins.'.$category->value)), 'last');
            $pricing->apply($category, array_combine(array_column($suggested, 'product_id'), array_map(fn ($s) => Fixed::parse($s['suggested_price']), $suggested)));
        }

        $duty = Fixed::zero();
        foreach ($result->lines as $l) {
            $duty = $duty->add($l->components[LandedCostCalculator::DUTY]);
        }
        $payables = app(PayablesService::class);
        $reference = 'shipment:'.$ref;
        $invoiceRate = Fixed::parse(number_format($rateNow[$currency] * (1 + mt_rand(-40, 40) / 10000), 4, '.', ''));
        $bill = fn (int $supplier, string $kind, Fixed $amount, string $cur, Fixed $r, string $no) => $payables->recordInvoice($supplier, $no, new DateTimeImmutable(CarbonImmutable::now()->toDateString()), $cur, $r, $amount, Fixed::zero(), InvoiceKind::from($kind), $reference);
        $goodsInv = $bill($sup[$currency], 'goods', $goodsForeign, $currency, $invoiceRate, $ref.'-INV');
        $freightInv = $bill($sup['LYD'], 'freight', $freight, 'LYD', Fixed::of(1), $ref.'-FRT');
        $insInv = $bill($sup['LYD'], 'insurance', $insurance, 'LYD', Fixed::of(1), $ref.'-INS');
        $clrInv = $bill($sup['LYD'], 'clearance', $clearance, 'LYD', Fixed::of(1), $ref.'-CLR');
        $dutyInv = $duty->isPositive() ? $bill($sup['CUSTOMS'], 'customs', $duty, 'LYD', Fixed::of(1), $ref.'-DUT') : null;

        $pay = function (int $supplierId, int $invoice, string $cur, Fixed $amount, Fixed $payRate, int $bankIdx) use ($payables, $bankGl) {
            $payables->pay($supplierId, new DateTimeImmutable(CarbonImmutable::now()->toDateString()), $cur, $payRate, 'transfer', 'TT-'.$invoice, [$invoice => $amount], $bankGl[$bankIdx]);
        };
        $recent = $arrive->diffInDays($today) < 45;
        $payRate = fn () => Fixed::parse(number_format($rateNow[$currency] * (1 + mt_rand(-100, 220) / 10000), 4, '.', ''));
        $this->later($arrive->addDays(9), function () use ($pay, $sup, $freightInv, $insInv, $clrInv, $dutyInv, $freight, $insurance, $clearance, $duty) {
            $pay($sup['LYD'], $freightInv, 'LYD', $freight, Fixed::of(1), 0);
            $pay($sup['LYD'], $insInv, 'LYD', $insurance, Fixed::of(1), 0);
            $pay($sup['LYD'], $clrInv, 'LYD', $clearance, Fixed::of(1), 1);
            if ($dutyInv !== null) {
                $pay($sup['CUSTOMS'], $dutyInv, 'LYD', $duty, Fixed::of(1), 0);
            }
        });
        if (! $recent) {   // older shipments: the supplier is paid in two instalments, then the final cost is settled
            $first = $goodsForeign->mul(Fixed::parse('0.3'));
            $this->later($arrive->addDays(10), fn () => $pay($sup[$currency], $goodsInv, $currency, $first, $payRate(), 0));
            $this->later($arrive->addDays(34), function () use ($pay, $sup, $currency, $goodsInv, $goodsForeign, $first, $payRate, $payables, $reference) {
                $pay($sup[$currency], $goodsInv, $currency, $goodsForeign->sub($first), $payRate(), 1);
                if (! Fixed::parse($payables->receiptStatus($reference)['open'])->isZero()) {
                    $payables->clearToVariance($reference, 'Rounding difference with the supplier invoice - فرق تقريب مع فاتورة المورد');
                }
                app(ShipmentSettlementService::class)->settle($reference, 1);
            });
        } elseif ($arrive->diffInDays($today) > 20) {
            $half = $goodsForeign->mul(Fixed::parse('0.5'));
            $this->later($arrive->addDays(20), fn () => $pay($sup[$currency], $goodsInv, $currency, $half, $payRate(), 0));   // the rest stays payable
        }
    }

    private function localPurchase(array $products, int $monthIndex): void
    {
        $sku = ['CEM-WP-25', 'TILEADH-25', 'EPS-30'][$monthIndex % 3];
        $svc = app(PurchaseOrderService::class);
        $po = $svc->draft('PO-'.CarbonImmutable::now()->format('ym').'-L', 'شركة الخدمات اللوجستية الليبية', 'LYD', Fixed::of(1),
            [new PurchaseOrderLine(null, $products[$sku]['id'], Fixed::of(600), Fixed::parse(number_format($products[$sku]['usd'] * 5.1, 4, '.', '')))], 1);
        $svc->submit($po->id);
        $svc->approve($po->id, 2, 3);
        $svc->receiveLocal($po->id, (int) DB::table('warehouses')->where('code', 'MAIN')->value('id'));
    }

    private function placeOrder(CarbonImmutable $day, array $customers, int $walkIn, array $bankGl, string $tillGl, array $banks, SalesOrderService $sales, StockLedgerRepository $stock): void
    {
        $roll = mt_rand(1, 100);
        $warehouse = (int) DB::table('warehouses')->where('code', 'MAIN')->value('id');
        if ($roll <= 40) {
            $customer = $walkIn;
            $terms = new PaymentTerms(PaymentMethod::Cash, $tillGl);
        } elseif ($roll <= 65) {
            $customer = mt_rand(0, 1) ? $walkIn : $customers[mt_rand(0, count($customers) - 1)];
            $terms = new PaymentTerms(PaymentMethod::Transfer, $bankGl[mt_rand(0, 1)]);
        } else {
            $customer = $customers[mt_rand(0, count($customers) - 1)];
            $terms = new PaymentTerms(PaymentMethod::Credit);
        }
        $big = DB::table('customers')->where('id', $customer)->value('category') === 'wholesale';
        $available = DB::table('stock_balances')->where('warehouse_id', $warehouse)->whereRaw('on_hand - reserved > 0')->pluck('product_id')->all();
        if ($available === []) {
            return;
        }
        shuffle($available);
        $lines = [];
        foreach (array_slice($available, 0, mt_rand(1, 3)) as $productId) {
            $lines[] = new OrderLineRequest((int) $productId, Fixed::of($big ? mt_rand(10, 80) : mt_rand(2, 15)));
        }
        try {
            $order = $sales->place($customer, $warehouse, $lines, false, false, $terms);
        } catch (Throwable) {
            $this->failedOrders++;   // not enough stock, credit limit, margin guard: the business rules said no

            return;
        }
        $deliver = $day->addDays(mt_rand(0, 2));
        $this->later($deliver, function () use ($sales, $order, $deliver, $terms, $customer) {
            $sales->ship($order->id);
            $sales->invoice($order->id);
            if ($terms->method === PaymentMethod::Credit) {
                $days = mt_rand(14, 60);
                $this->later($deliver->addDays($days), function () use ($order, $customer) {
                    $invoice = DB::table('sales_invoices')->where('sales_order_id', $order->id)->first();
                    $open = Fixed::units((int) $invoice->gross - (int) $invoice->paid - (int) $invoice->credited);
                    if ($open->isPositive()) {
                        $bank = DB::table('bank_accounts')->orderBy('id')->get();
                        $gl = mt_rand(0, 3) === 0 ? (string) config('erp.accounts.cash') : (string) $bank[mt_rand(0, 1)]->gl_account;
                        app(ReceivablesService::class)->receive($customer, new DateTimeImmutable(CarbonImmutable::now()->toDateString()), $gl === (string) config('erp.accounts.cash') ? 'cash' : 'transfer', 'RCPT-'.$invoice->number, [(int) $invoice->id => $open], $gl);
                    }
                });
                if (mt_rand(1, 100) <= 6) {   // an occasional return of part of a credit sale
                    $this->later($deliver->addDays(4), function () use ($order) {
                        $line = DB::table('sales_order_lines')->where('sales_order_id', $order->id)->first();
                        app(SalesReturnService::class)->returnGoods($order->id, [(int) $line->id => Fixed::of(1)], 'Damaged on arrival - تالف عند الاستلام');
                    });
                }
            }
        });
    }

    /** Employees, a few months of attendance, an advance and paid payrolls for the last full months. */
    private function hr(): void
    {
        $wh = (int) DB::table('warehouses')->where('code', 'MAIN')->value('id');
        $branch = (int) DB::table('warehouses')->where('code', 'BR1')->value('id');
        $hired = CarbonImmutable::today()->subYears(2)->toDateString();
        foreach ([['E001', 'أحمد المبروك', 'مندوب مبيعات', 'المبيعات', 3500, $wh], ['E002', 'سالم الفيتوري', 'محاسب', 'المالية', 4200, $wh], ['E003', 'مفتاح الزروق', 'أمين مخزن', 'المخازن', 3000, $wh],
            ['E004', 'عمر بن علي', 'سائق', 'التوصيل', 2400, $wh], ['E005', 'خالد الشريف', 'عامل تحميل', 'المخازن', 2000, $branch], ['E006', 'يوسف الترهوني', 'مسؤول مشتريات', 'المشتريات', 3800, $wh]] as [$code, $name, $job, $dep, $salary, $w]) {
            $depId = DB::table('departments')->where('name', $dep)->value('id') ?? app(OrgStructureService::class)->createDepartment($dep, null, null);
            $posId = DB::table('job_positions')->where('title', $job)->where('department_id', $depId)->value('id') ?? app(OrgStructureService::class)->createPosition($job, (int) $depId, 1);
            $id = DB::table('employees')->insertGetId(['code' => $code, 'name' => $name, 'department_id' => $depId, 'job_position_id' => $posId, 'warehouse_id' => $w, 'hire_date' => $hired, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            app(ContractService::class)->hire($id, $code, new DateTimeImmutable($hired), Fixed::fromWhole($salary), Fixed::fromWhole(in_array($code, ['E004', 'E005'], true) ? 150 : 0), null);
        }
        $ids = DB::table('employees')->pluck('id')->all();
        $payroll = app(PayrollService::class);
        $today = CarbonImmutable::today();
        for ($back = 3; $back >= 1; $back--) {
            $month = $today->startOfMonth()->subMonths($back);
            foreach ($ids as $id) {
                foreach ([mt_rand(2, 8), mt_rand(12, 20)] as $d) {
                    if (mt_rand(1, 100) <= 12) {   // an occasional absence
                        DB::table('attendance')->insert(['employee_id' => $id, 'work_date' => $month->addDays($d)->toDateString(), 'status' => 'absent']);
                    }
                }
            }
            if ($back === 3) {
                $payroll->giveAdvance($ids[3], Fixed::of(600), Fixed::of(200), (string) config('erp.accounts.cash'), $month->addDays(3)->toDateString(), 'سلفة');
            }
            $run = $payroll->create($month->format('Y-m'), 0);
            $payroll->approve($run, 0);
            $payroll->pay($run, (string) config('erp.accounts.cash'), $month->endOfMonth()->toDateString());
        }
    }

    private function users(): void
    {
        $access = app(AccessRepository::class);
        $wh = (int) DB::table('warehouses')->where('code', 'MAIN')->value('id');
        foreach ([['manager', 'general_manager', []], ['finance', 'finance_manager', []], ['purchasing', 'purchasing_manager', []], ['warehouse', 'warehouse_clerk', [$wh]], ['sales', 'sales_rep', [$wh]], ['accountant', 'accountant', []], ['hr', 'hr_manager', []]] as [$name, $role, $warehouses]) {
            $user = User::query()->create(['name' => 'Demo '.$name, 'email' => $name.'@demo.test', 'password' => self::DEMO_PASSWORD, 'active' => true]);
            $access->replaceAccess($user->id, [$role], $warehouses);
        }
        // the demo sales were made through the services: attribute them to the demo sales representative
        $rep = (int) User::query()->where('email', 'sales@demo.test')->value('id');
        DB::table('sales_orders')->whereNull('created_by')->update(['created_by' => $rep]);
        DB::table('sales_orders')->whereIn('customer_id', DB::table('customers')->where('is_walk_in', true)->select('id'))->whereNull('customer_name')->update(['customer_name' => 'غير معروف']);
        $this->line('Demo users (password '.self::DEMO_PASSWORD.'): manager@, finance@, purchasing@, warehouse@, sales@, accountant@, hr@ demo.test  (administrator: the account you created)');
    }
}
