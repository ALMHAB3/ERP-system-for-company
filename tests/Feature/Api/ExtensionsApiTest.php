<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Shared\Domain\Fixed;

/** E-invoicing (sealed chain, UBL, QR), serial numbers, webhooks and CSV exports. */
final class ExtensionsApiTest extends ApiTestCase
{
    private int $customer;

    private object $rep;

    private object $clerk;

    private object $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = DB::table('customers')->insertGetId(['name' => 'Al Noor Trading', 'category' => 'retail', 'credit_limit' => 0, 'terms_days' => 30, 'tax_id' => 'TX-123', 'address' => 'Tripoli', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => 'retail', 'price' => 100 * Fixed::FACTOR]);
        [$this->rep, $this->clerk, $this->finance] = [$this->userWith(['sales_rep'], [$this->warehouse]), $this->userWith(['warehouse_clerk'], [$this->warehouse]), $this->userWith(['finance_manager'])];
        $this->as($this->finance)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '1'])->assertCreated();
        $s = $this->as($this->userWith(['purchasing_manager']))->postJson('/api/shipments', ['reference' => 'X-1', 'currency' => 'USD', 'lines' => [['product_id' => $this->product, 'quantity' => '1000', 'unit_price' => '10', 'duty_rate' => '0']]])->assertCreated()->json('id');
        $this->as($this->finance)->postJson("/api/shipments/{$s}/finalize")->assertOk();
        $this->as($this->clerk)->postJson("/api/shipments/{$s}/receive", ['warehouse_id' => $this->warehouse])->assertOk();
    }

    private function place(string $qty = '2'): int
    {
        return $this->as($this->rep)->postJson('/api/sales-orders', ['customer_id' => $this->customer, 'warehouse_id' => $this->warehouse, 'lines' => [['product_id' => $this->product, 'quantity' => $qty]]])->assertCreated()->json('id');
    }

    private function invoiceSale(string $qty = '2'): int
    {
        $id = $this->place($qty);
        $this->as($this->clerk)->postJson("/api/sales-orders/{$id}/ship")->assertOk();
        $this->as($this->finance)->postJson("/api/sales-orders/{$id}/invoice")->assertCreated();

        return (int) DB::table('sales_invoices')->where('sales_order_id', $id)->value('id');
    }

    public function test_invoice_is_sealed_into_a_chain_and_exports_as_json_ubl_and_qr(): void
    {
        $a = $this->invoiceSale();
        $b = $this->invoiceSale();
        $doc = $this->as($this->finance)->getJson("/api/sales-invoices/{$a}")->assertOk()->json();

        $this->assertSame(config('erp.company_name'), $doc['seller']['name']);
        $this->assertSame('TX-123', $doc['buyer']['tax_id']);
        $this->assertSame('200.0000', $doc['totals']['net']);
        $this->assertCount(1, $doc['lines']);
        $this->assertSame(64, strlen($doc['fingerprint']));
        $this->assertNull($doc['previous_fingerprint']);
        $this->assertSame($doc['fingerprint'], $this->as($this->finance)->getJson("/api/sales-invoices/{$b}")->json('previous_fingerprint'));

        $tlv = base64_decode($doc['qr'], true);                      // tag 1 = seller name, length-prefixed UTF-8
        $this->assertSame(config('erp.company_name'), substr($tlv, 2, ord($tlv[1])));

        $xml = $this->as($this->finance)->get("/api/sales-invoices/{$a}/ubl")->assertOk()->getContent();
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame($doc['number'], $dom->getElementsByTagNameNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'ID')->item(0)->textContent);

        $this->as($this->finance)->getJson('/api/sales-invoices/verify-chain')->assertJsonPath('checked', 2)->assertJsonPath('broken', []);
        $this->artisan('invoices:verify')->assertSuccessful();
    }

    public function test_editing_a_sealed_invoice_breaks_the_chain(): void
    {
        $a = $this->invoiceSale();
        $this->invoiceSale();
        DB::table('sales_invoices')->where('id', $a)->update(['net' => 1]);      // tampering outside the application

        $this->artisan('invoices:verify')->assertFailed();
        $this->assertNotEmpty($this->as($this->finance)->getJson('/api/sales-invoices/verify-chain')->json('broken'));
    }

    public function test_serial_tracked_units_must_be_named_before_shipping_and_are_traceable(): void
    {
        DB::table('products')->where('id', $this->product)->update(['tracks_serials' => true]);
        $stock = $this->as($this->userWith(['warehouse_clerk'], [$this->warehouse]));
        $stock->postJson('/api/serials', ['product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'serials' => ['SN-1', 'SN-2', 'SN-3']])->assertCreated()->assertJsonPath('registered', 3);
        $stock->postJson('/api/serials', ['product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'serials' => ['SN-3']])->assertUnprocessable();   // duplicate

        $id = $this->place('2');
        $line = (int) DB::table('sales_order_lines')->where('sales_order_id', $id)->value('id');
        $this->as($this->clerk)->postJson("/api/sales-orders/{$id}/ship")->assertUnprocessable();                       // no serials chosen
        $this->as($this->rep)->putJson("/api/sales-orders/{$id}/lines/{$line}/serials", ['serials' => ['SN-1']])->assertNoContent();
        $this->as($this->clerk)->postJson("/api/sales-orders/{$id}/ship")->assertUnprocessable();                       // 1 of 2
        $this->as($this->rep)->putJson("/api/sales-orders/{$id}/lines/{$line}/serials", ['serials' => ['SN-1', 'SN-2']])->assertNoContent();
        $this->as($this->clerk)->postJson("/api/sales-orders/{$id}/ship")->assertOk();
        $this->as($this->finance)->postJson("/api/sales-orders/{$id}/invoice")->assertCreated();

        $t = $this->as($this->userWith(['warehouse_clerk'], [$this->warehouse]))->getJson('/api/serials/trace/SN-2')->assertOk()->json('data.0');
        $this->assertSame('sold', $t['status']);
        $this->assertSame('Al Noor Trading', $t['customer']);
        $this->assertStringStartsWith('INV-', $t['invoice']);
        $this->assertSame('in_stock', DB::table('product_serials')->where('serial', 'SN-3')->value('status'));
    }

    public function test_cancelling_an_order_frees_its_serials_and_serials_cannot_exceed_stock(): void
    {
        DB::table('products')->where('id', $this->product)->update(['tracks_serials' => true]);
        $keeper = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $this->as($keeper)->postJson('/api/serials', ['product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'serials' => ['A-1', 'A-2']])->assertCreated();
        $id = $this->place('1');
        $line = (int) DB::table('sales_order_lines')->where('sales_order_id', $id)->value('id');
        $this->as($this->rep)->putJson("/api/sales-orders/{$id}/lines/{$line}/serials", ['serials' => ['A-1']])->assertNoContent();
        $this->assertSame('reserved', DB::table('product_serials')->where('serial', 'A-1')->value('status'));
        $this->as($this->rep)->postJson("/api/sales-orders/{$id}/cancel")->assertSuccessful();
        $this->assertSame('in_stock', DB::table('product_serials')->where('serial', 'A-1')->value('status'));

        DB::table('stock_balances')->where('product_id', $this->product)->update(['on_hand' => 2 * Fixed::FACTOR]);
        $this->as($keeper)->postJson('/api/serials', ['product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'serials' => ['A-3']])->assertUnprocessable();   // 3 serials > 2 units on hand
    }

    public function test_webhook_is_queued_with_the_sale_and_delivered_signed_with_retry(): void
    {
        $admin = $this->userWith(['admin']);
        $this->as($this->finance)->getJson('/api/integrations/webhooks')->assertForbidden();
        $this->as($admin)->postJson('/api/integrations/webhooks', ['name' => 'x', 'url' => 'http://169.254.169.254/latest', 'events' => ['invoice.issued']])->assertUnprocessable();
        $created = $this->as($admin)->postJson('/api/integrations/webhooks', ['name' => 'Accounting', 'url' => 'http://203.0.113.9/hook', 'events' => ['invoice.issued']])->assertCreated()->json();
        $this->assertNotEmpty($created['secret']);

        $this->invoiceSale();
        $this->assertSame(1, DB::table('webhook_deliveries')->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('webhook_deliveries')->where('event', 'payment.received')->count());   // not subscribed

        Http::fake(['203.0.113.9/*' => Http::sequence()->push('no', 500)->push('ok', 200)]);
        $this->artisan('webhooks:deliver')->assertSuccessful();
        $d = DB::table('webhook_deliveries')->first();
        $this->assertSame('pending', $d->status);
        $this->assertSame(1, (int) $d->attempts);
        DB::table('webhook_deliveries')->update(['next_attempt_at' => now()->subMinute()]);
        $this->artisan('webhooks:deliver')->assertSuccessful();
        $this->assertSame('delivered', DB::table('webhook_deliveries')->value('status'));

        Http::assertSent(function ($request) use ($created) {
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-ERP-Timestamp')[0].'.'.$request->body(), $created['secret']);

            return $request->header('X-ERP-Signature')[0] === $expected && json_decode($request->body(), true)['event'] === 'invoice.issued';
        });
    }

    public function test_csv_export_is_excel_safe(): void
    {
        $this->invoiceSale();
        DB::table('customers')->where('id', $this->customer)->update(['name' => '=HYPERLINK("x")']);
        $csv = $this->as($this->finance)->get('/api/export/invoices.csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('INV-', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }
}
