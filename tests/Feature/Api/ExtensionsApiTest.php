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

    public function test_invoice_prints_the_company_and_the_branch_details_entered_on_screen(): void
    {
        $id = $this->invoiceSale();
        $admin = $this->userWith(['admin']);
        $this->as($admin)->putJson('/api/admin/company', ['name' => 'شركة الاختبار', 'tax_id' => 'TAX-9', 'commercial_register' => 'CR-1', 'address' => 'طرابلس', 'phone' => '091'])->assertOk();
        $this->as($admin)->putJson("/api/admin/branches/{$this->warehouse}", ['name' => 'فرع بنغازي', 'city' => 'بنغازي', 'address' => 'شارع جمال', 'phone' => '092'])->assertNoContent();

        $doc = $this->as($this->finance)->getJson("/api/sales-invoices/{$id}")->assertOk()->json();
        $this->assertSame(['شركة الاختبار', 'TAX-9', 'CR-1'], [$doc['seller']['name'], $doc['seller']['tax_id'], $doc['seller']['commercial_register']]);
        $this->assertSame(['فرع بنغازي', 'بنغازي', 'شارع جمال', '092'], [$doc['branch']['name'], $doc['branch']['city'], $doc['branch']['address'], $doc['branch']['phone']]);
        $tlv = base64_decode($doc['qr'], true);
        $this->assertSame('شركة الاختبار', substr($tlv, 2, ord($tlv[1])));
        // editing the details never breaks the tamper-evident chain of invoices already issued
        $this->as($this->finance)->getJson('/api/sales-invoices/verify-chain')->assertJsonPath('broken', []);
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
