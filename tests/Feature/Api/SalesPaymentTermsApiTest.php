<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Domain\Fixed;

/** Cash and bank-transfer sales are paid at the counter into the right account; credit needs a registered customer. */
final class SalesPaymentTermsApiTest extends ApiTestCase
{
    private int $walkIn;

    private int $registered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walkIn = (int) DB::table('customers')->where('is_walk_in', true)->value('id');
        $this->registered = DB::table('customers')->insertGetId(['name' => 'Al Noor Trading', 'category' => 'retail', 'credit_limit' => 0, 'terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => 'direct', 'price' => 100 * Fixed::FACTOR]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => 'retail', 'price' => 100 * Fixed::FACTOR]);
        $buyer = $this->userWith(['purchasing_manager']);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $this->as($this->userWith(['finance_manager']))->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '1'])->assertCreated();
        $s = $this->as($buyer)->postJson('/api/shipments', ['reference' => 'ST-1', 'currency' => 'USD', 'lines' => [['product_id' => $this->product, 'quantity' => '1000', 'unit_price' => '10', 'duty_rate' => '0']]])->assertCreated()->json('id');
        $this->as($this->userWith(['finance_manager']))->postJson("/api/shipments/{$s}/finalize")->assertOk();
        $this->as($clerk)->postJson("/api/shipments/{$s}/receive", ['warehouse_id' => $this->warehouse])->assertOk();
    }

    private function balance(string $account): Fixed
    {
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            if ($r['account_code'] === $account) {
                return Fixed::parse($r['debit'])->sub(Fixed::parse($r['credit']));
            }
        }

        return Fixed::zero();
    }

    private function order(object $by, int $customer, array $extra): TestResponse
    {
        return $this->as($by)->postJson('/api/sales-orders', ['customer_id' => $customer, 'warehouse_id' => $this->warehouse, 'lines' => [['product_id' => $this->product, 'quantity' => '10']]] + $extra);
    }

    private function sellAndInvoice(object $rep, object $clerk, object $finance, int $customer, array $extra): int
    {
        $id = $this->order($rep, $customer, $extra)->assertCreated()->json('id');
        $this->as($clerk)->postJson("/api/sales-orders/{$id}/ship")->assertOk();
        $this->as($finance)->postJson("/api/sales-orders/{$id}/invoice")->assertCreated();

        return $id;
    }

    public function test_cash_sale_to_the_cash_customer_is_paid_into_the_till_at_invoicing(): void
    {
        [$rep, $clerk, $finance] = [$this->userWith(['sales_rep'], [$this->warehouse]), $this->userWith(['warehouse_clerk'], [$this->warehouse]), $this->userWith(['finance_manager'])];
        $id = $this->sellAndInvoice($rep, $clerk, $finance, $this->walkIn, ['payment_method' => 'cash']);

        $this->assertSame('1000.0000', (string) $this->balance('1110'));      // 10 x 100 into the cash till
        $this->assertSame('0.0000', (string) $this->balance('1200'));         // nothing left owing
        $this->assertSame(1, DB::table('customer_receipts')->count());
        $this->assertSame(0, (int) DB::table('sales_invoices')->selectRaw('gross - paid as open')->value('open'));
        $this->as($rep)->getJson("/api/sales-orders/{$id}")->assertJsonPath('payment_method', 'cash');
    }

    public function test_bank_transfer_sale_goes_to_the_chosen_bank_and_requires_one(): void
    {
        [$rep, $clerk, $finance] = [$this->userWith(['sales_rep'], [$this->warehouse]), $this->userWith(['warehouse_clerk'], [$this->warehouse]), $this->userWith(['finance_manager'])];
        $a = $this->as($finance)->postJson('/api/finance/bank-accounts', ['name' => 'Commercial Bank', 'currency' => 'LYD', 'account_number' => '001'])->assertCreated()->json('id');
        $b = $this->as($finance)->postJson('/api/finance/bank-accounts', ['name' => 'Sahara Bank', 'currency' => 'LYD'])->assertCreated()->json('id');

        $this->order($rep, $this->walkIn, ['payment_method' => 'transfer'])->assertUnprocessable()->assertJsonPath('message', 'Choose the bank account the money goes through.');
        $this->sellAndInvoice($rep, $clerk, $finance, $this->walkIn, ['payment_method' => 'transfer', 'bank_account_id' => $b, 'payment_reference' => 'TRX-77']);

        $banks = collect($this->as($finance)->getJson('/api/finance/bank-accounts')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame('1000.0000', $banks['Sahara Bank']['balance']);
        $this->assertSame('0.0000', $banks['Commercial Bank']['balance']);
        $this->assertSame('1120', $banks['Commercial Bank']['gl_account']);
        $this->assertSame('1121', $banks['Sahara Bank']['gl_account']);
        $this->assertSame('0.0000', $banks[null]['balance']);                    // cash till untouched
        $this->assertSame('TRX-77', DB::table('customer_receipts')->value('reference'));
        $this->assertNotSame($a, $b);
    }

    public function test_credit_needs_a_registered_customer_and_stays_open_until_collected_into_a_chosen_bank(): void
    {
        [$rep, $clerk, $finance] = [$this->userWith(['sales_rep'], [$this->warehouse]), $this->userWith(['warehouse_clerk'], [$this->warehouse]), $this->userWith(['finance_manager'])];

        $this->order($rep, $this->walkIn, ['payment_method' => 'credit'])->assertUnprocessable()->assertJsonPath('code', 'CreditNeedsRegisteredCustomer');
        $this->assertSame(0, DB::table('sales_orders')->count());
        $this->assertSame('1000.0000', (string) Fixed::units(DB::table('stock_balances')->value('on_hand') - DB::table('stock_balances')->value('reserved')), 'refused order must not hold stock');

        $this->sellAndInvoice($rep, $clerk, $finance, $this->registered, ['payment_method' => 'credit']);
        $this->assertSame('1000.0000', (string) $this->balance('1200'));         // owed by the customer
        $this->assertSame(0, DB::table('customer_receipts')->count());

        $bank = $this->as($finance)->postJson('/api/finance/bank-accounts', ['name' => 'Wahda Bank', 'currency' => 'LYD'])->assertCreated()->json('id');
        $invoice = DB::table('sales_invoices')->value('id');
        $base = ['customer_id' => $this->registered, 'received_on' => '2026-06-01', 'reference' => 'R1', 'allocations' => [['invoice_id' => $invoice, 'amount' => '400']]];
        $this->as($finance)->postJson('/api/receivables/receipts', $base + ['method' => 'transfer'])->assertUnprocessable();                     // no bank chosen
        $this->as($finance)->postJson('/api/receivables/receipts', $base + ['method' => 'transfer', 'bank_account_id' => $bank])->assertCreated();
        $this->as($finance)->postJson('/api/receivables/receipts', ['reference' => 'R2', 'method' => 'cash', 'allocations' => [['invoice_id' => $invoice, 'amount' => '100']]] + $base)->assertCreated();

        $this->assertSame('400.0000', (string) $this->balance('1120'));          // the transfer
        $this->assertSame('100.0000', (string) $this->balance('1110'));          // the cash
        $this->assertSame('500.0000', (string) $this->balance('1200'));
    }

    public function test_paid_now_sales_do_not_count_against_the_credit_limit(): void
    {
        [$rep, $clerk, $finance] = [$this->userWith(['sales_rep'], [$this->warehouse]), $this->userWith(['warehouse_clerk'], [$this->warehouse]), $this->userWith(['finance_manager'])];
        DB::table('customers')->where('id', $this->registered)->update(['credit_limit' => 500 * Fixed::FACTOR]);

        $this->order($rep, $this->registered, ['payment_method' => 'credit'])->assertUnprocessable();          // 1,000 > 500 limit
        $this->sellAndInvoice($rep, $clerk, $finance, $this->registered, ['payment_method' => 'cash']);         // paid on the spot: no exposure
        $this->assertSame('0.0000', (string) $this->balance('1200'));
    }

    public function test_supplier_payments_leave_from_cash_or_the_chosen_bank(): void
    {
        $finance = $this->userWith(['finance_manager']);
        $bank = $this->as($finance)->postJson('/api/finance/bank-accounts', ['name' => 'Bank X', 'currency' => 'USD'])->assertCreated()->json('id');
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'S', 'currency' => 'LYD', 'terms_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $bill = fn (string $n) => $this->as($finance)->postJson('/api/payables/invoices', ['supplier_id' => $supplier, 'number' => $n, 'invoice_date' => '2026-05-01', 'currency' => 'LYD', 'subtotal' => '100', 'kind' => 'expense', 'expense_account' => '5400'])->assertCreated()->json('id');
        $pay = fn (int $inv, array $extra) => $this->as($finance)->postJson('/api/payables/payments', ['supplier_id' => $supplier, 'paid_on' => '2026-05-20', 'currency' => 'LYD', 'reference' => 'p', 'allocations' => [['invoice_id' => $inv, 'amount' => '100']]] + $extra);

        $pay($bill('E1'), ['method' => 'transfer'])->assertUnprocessable();                                      // transfer needs a bank
        $pay($bill('E2'), ['method' => 'transfer', 'bank_account_id' => $bank])->assertCreated();
        $pay($bill('E3'), ['method' => 'cash'])->assertCreated();

        $this->assertSame('-100.0000', (string) $this->balance('1120'));
        $this->assertSame('-100.0000', (string) $this->balance('1110'));
    }

    public function test_only_finance_manages_banks(): void
    {
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);
        $this->as($rep)->postJson('/api/finance/bank-accounts', ['name' => 'X', 'currency' => 'LYD'])->assertForbidden();
        $this->as($this->userWith(['finance_manager']))->postJson('/api/finance/bank-accounts', ['name' => 'X', 'currency' => 'GBP'])->assertUnprocessable();
        $this->as($this->userWith(['finance_manager']))->getJson('/api/lookups')->assertOk()->assertJsonPath('customers.0.is_walk_in', true);
    }
}
