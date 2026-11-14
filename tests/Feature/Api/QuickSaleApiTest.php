<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Shared\Domain\Fixed;

/** One-screen sale, sales-rep-only selling, manager's period summary, shipment prices and expiry rules. */
final class QuickSaleApiTest extends ApiTestCase
{
    private object $rep;

    private int $registered;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registered = DB::table('customers')->insertGetId(['name' => 'Al Noor Trading', 'category' => 'retail', 'credit_limit' => 0, 'terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $this->rep = $this->userWith(['sales_rep'], [$this->warehouse]);
        $finance = $this->userWith(['finance_manager']);
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '1'])->assertCreated();
        $s = $this->as($this->userWith(['purchasing_manager']))->postJson('/api/shipments', ['reference' => 'Q-1', 'currency' => 'USD', 'lines' => [['product_id' => $this->product, 'quantity' => '1000', 'unit_price' => '10', 'duty_rate' => '0', 'sale_price' => '85']]])->assertCreated()->json('id');
        $this->as($finance)->postJson("/api/shipments/{$s}/finalize")->assertOk();
        $this->as($this->userWith(['warehouse_clerk'], [$this->warehouse]))->postJson("/api/shipments/{$s}/receive", ['warehouse_id' => $this->warehouse])->assertOk();
    }

    private function sale(array $extra = []): TestResponse
    {
        return $this->as($this->rep)->postJson('/api/sales/quick', $extra + ['warehouse_id' => $this->warehouse, 'payment_method' => 'cash', 'lines' => [['product_id' => $this->product, 'quantity' => '2']]]);
    }

    public function test_price_typed_with_the_shipment_becomes_the_selling_price(): void
    {
        $this->assertSame(85 * Fixed::FACTOR, (int) DB::table('price_list_items')->where('product_id', $this->product)->whereNull('category')->value('price'));
        $r = $this->sale(['customer_name' => 'أحمد'])->assertCreated()->json();
        $this->assertSame('170.0000', $r['gross']);
    }

    public function test_cash_sale_in_one_step_uses_the_typed_name_or_unknown(): void
    {
        $r = $this->sale(['customer_name' => 'أحمد علي'])->assertCreated()->json();
        $this->assertStringStartsWith('INV-', $r['invoice_number']);
        $this->assertSame('invoiced', DB::table('sales_orders')->where('id', $r['order_id'])->value('status'));
        $this->assertSame('أحمد علي', DB::table('sales_orders')->where('id', $r['order_id'])->value('customer_name'));
        $this->assertSame((int) $this->rep->id, (int) DB::table('sales_orders')->where('id', $r['order_id'])->value('created_by'));
        $this->assertSame('أحمد علي', $this->as($this->finance())->getJson("/api/sales-invoices/{$r['invoice_id']}")->json('buyer.name'));

        $u = $this->sale()->assertCreated()->json();
        $this->assertSame('غير معروف', DB::table('sales_orders')->where('id', $u['order_id'])->value('customer_name'));
        $this->assertSame(0, (int) DB::table('sales_invoices')->where('id', $u['invoice_id'])->selectRaw('gross - paid as open')->value('open'));   // paid at the counter
    }

    public function test_debt_sale_needs_a_registered_customer(): void
    {
        $this->sale(['payment_method' => 'credit', 'customer_name' => 'زبون عابر'])->assertUnprocessable();
        $walkIn = (int) DB::table('customers')->where('is_walk_in', true)->value('id');
        $this->sale(['payment_method' => 'credit', 'customer_id' => $walkIn])->assertUnprocessable();
        $r = $this->sale(['payment_method' => 'credit', 'customer_id' => $this->registered])->assertCreated()->json();
        $this->assertSame(170 * Fixed::FACTOR, (int) DB::table('sales_invoices')->where('id', $r['invoice_id'])->selectRaw('gross - paid as open')->value('open'));
    }

    public function test_sale_is_all_or_nothing(): void
    {
        $this->sale(['lines' => [['product_id' => $this->product, 'quantity' => '999999']]])->assertStatus(409);
        $this->assertSame(0, DB::table('sales_orders')->count());
        $this->assertSame(1000 * Fixed::FACTOR, (int) DB::table('stock_balances')->where('product_id', $this->product)->value('on_hand'));
    }

    public function test_managers_cannot_sell_but_see_the_sales_by_period_and_rep(): void
    {
        foreach (['admin', 'general_manager'] as $role) {
            $this->as($this->userWith([$role]))->postJson('/api/sales/quick', ['warehouse_id' => $this->warehouse, 'payment_method' => 'cash', 'lines' => [['product_id' => $this->product, 'quantity' => '1']]])->assertForbidden();
        }
        $this->sale()->assertCreated();
        $this->sale(['payment_method' => 'credit', 'customer_id' => $this->registered])->assertCreated();
        $r = $this->as($this->userWith(['general_manager']))->getJson('/api/reports/sales-summary?period=today')->assertOk()->json();
        $this->assertSame(2, $r['totals']['count']);
        $this->assertSame('340.0000', $r['totals']['gross']);
        $this->assertSame(2, $r['by_rep'][0]['count']);
        $this->assertCount(2, $r['by_method']);
        $this->as($this->rep)->getJson('/api/reports/sales-summary')->assertForbidden();   // a rep cannot open the managers' report
    }

    public function test_product_with_expiry_needs_a_date_on_the_shipment(): void
    {
        $p = $this->makeProduct('MILK-1');
        $this->as($this->userWith(['purchasing_manager']))->putJson("/api/products/{$p}", ['has_expiry' => true])->assertNoContent();
        $buyer = $this->userWith(['purchasing_manager']);
        $line = ['product_id' => $p, 'quantity' => '10', 'unit_price' => '2', 'duty_rate' => '0'];
        $this->as($buyer)->postJson('/api/shipments', ['reference' => 'E-1', 'currency' => 'USD', 'lines' => [$line]])->assertUnprocessable();
        $this->as($buyer)->postJson('/api/shipments', ['reference' => 'E-1', 'currency' => 'USD', 'lines' => [$line + ['expiry_date' => '2030-01-01']]])->assertCreated();
        $this->as($buyer)->postJson('/api/shipments', ['reference' => 'E-2', 'currency' => 'USD', 'lines' => [['product_id' => $this->product] + $line + ['product_id' => $this->product]]])->assertCreated();   // no expiry product: no date needed
    }

    private function finance(): object
    {
        return $this->userWith(['finance_manager']);
    }
}
