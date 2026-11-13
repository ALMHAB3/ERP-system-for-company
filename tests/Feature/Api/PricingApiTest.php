<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;

final class PricingApiTest extends ApiTestCase
{
    private function landed(string $currency, string $rate, string $price): void
    {
        $finance = $this->userWith(['finance_manager']);
        $buyer = $this->userWith(['purchasing_manager']);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => $currency, 'rate' => $rate])->assertCreated();
        $id = $this->as($buyer)->postJson('/api/shipments', ['reference' => 'P-1', 'currency' => $currency, 'lines' => [['product_id' => $this->product, 'quantity' => '100', 'unit_price' => $price, 'duty_rate' => '0']]])->assertCreated()->json('id');
        $this->as($finance)->postJson("/api/shipments/{$id}/finalize")->assertOk();
        $this->as($clerk)->postJson("/api/shipments/{$id}/receive", ['warehouse_id' => $this->warehouse])->assertOk();
    }

    public function test_suggested_price_is_cost_plus_margin_rounded_up_to_five_dinar(): void
    {
        $this->landed('USD', '4.85', '50');           // landed cost 242.5 LYD per unit
        $buyer = $this->userWith(['purchasing_manager']);

        $row = $this->as($buyer)->getJson('/api/pricing/suggestions?category=retail&margin_pct=45')->assertOk()->assertJsonPath('step', '5.0000')->json('data.0');
        $this->assertSame('242.5000', $row['unit_cost']);
        $this->assertSame('351.6250', $row['raw_price']);              // 242.5 x 1.45
        $this->assertSame('355.0000', $row['suggested_price']);        // rounded UP to the next 5
        $this->assertSame('USD', $row['last_purchase']['currency']);
        $this->assertSame('50.0000', $row['last_purchase']['price']);
        $this->assertSame('4.8500', $row['last_purchase']['rate']);

        $row = $this->as($buyer)->getJson('/api/pricing/suggestions?category=retail&margin_pct=47')->json('data.0');   // 356.475 -> 360
        $this->assertSame('360.0000', $row['suggested_price']);
    }

    public function test_applying_prices_rounds_again_on_the_server_audits_and_feeds_pricing(): void
    {
        $this->landed('EUR', '5.3', '40');
        $buyer = $this->userWith(['purchasing_manager']);
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);

        $this->as($buyer)->postJson('/api/pricing/apply', ['category' => 'retail', 'prices' => [['product_id' => $this->product, 'price' => '353']]])->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame(355 * 10000, (int) DB::table('price_list_items')->where('product_id', $this->product)->where('category', 'retail')->value('price'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'price.suggestions_applied')->exists());

        $this->as($rep)->getJson('/api/pricing/suggestions?category=retail')->assertForbidden();
        $this->as($rep)->postJson('/api/pricing/apply', ['category' => 'retail', 'prices' => [['product_id' => $this->product, 'price' => '1']]])->assertForbidden();
        $this->as($buyer)->postJson('/api/pricing/apply', ['category' => 'retail', 'prices' => [['product_id' => $this->product, 'price' => '0']]])->assertUnprocessable();
    }

    public function test_average_basis_and_products_never_bought_are_handled(): void
    {
        $this->makeProduct('NEVER');
        $this->landed('USD', '5', '10');
        $buyer = $this->userWith(['purchasing_manager']);
        $rows = $this->as($buyer)->getJson('/api/pricing/suggestions?category=wholesale&basis=average')->assertOk()->json('data');
        $this->assertCount(1, $rows);                                   // NEVER has no cost, so no suggestion
        $this->assertSame('50.0000', $rows[0]['unit_cost']);
        $this->assertSame('55.0000', $rows[0]['suggested_price']);      // 50 x 1.10 = 55.0 (already a multiple of 5)
    }

    public function test_preview_shows_dinar_cost_and_rounded_selling_prices_before_saving(): void
    {
        $buyer = $this->userWith(['purchasing_manager']);

        // 100 USD at 9.5 = 950 dinar, no other costs
        $r = $this->as($buyer)->postJson('/api/shipments/preview', ['currency' => 'USD', 'exchange_rate' => '9.5', 'lines' => [['quantity' => '1', 'unit_price' => '100']]])->assertOk();
        $this->assertSame('950.0000', $r->json('lines.0.purchase_unit_base'));
        $this->assertSame('950.0000', $r->json('lines.0.landed_unit_cost'));
        $this->assertSame(['wholesale' => '1045.0000', 'retail' => '1190.0000', 'direct' => '1235.0000'], $r->json('lines.0.suggested'));   // 1187.5 -> 1190

        // with freight by volume, duty 5% on CIF and a custom margin
        $r = $this->as($buyer)->postJson('/api/shipments/preview', [
            'currency' => 'USD', 'exchange_rate' => '9.5', 'margin_pct' => '20',
            'lines' => [['quantity' => '10', 'unit_price' => '100', 'volume_cbm' => '2', 'duty_rate' => '0.05']],
            'costs' => [['code' => 'FREIGHT', 'amount' => '500', 'basis' => 'volume', 'in_customs_value' => true]],
        ])->assertOk();
        $this->assertSame('9500.0000', (string) Fixed::parse($r->json('lines.0.components.PURCHASE')));
        $this->assertSame('500.0000', $r->json('lines.0.components.FREIGHT'));
        $this->assertSame('500.0000', $r->json('lines.0.components.DUTY'));          // 5% of CIF 10,000
        $this->assertSame('1050.0000', $r->json('lines.0.landed_unit_cost'));        // 10,500 / 10
        $this->assertSame('1260.0000', $r->json('lines.0.suggested.retail'));        // 1050 x 1.20, a multiple of 5

        $this->assertSame(0, DB::table('shipments')->count());                                  // nothing saved
        $this->as($buyer)->postJson('/api/shipments/preview', ['currency' => 'USD', 'exchange_rate' => '9.5', 'lines' => [['quantity' => '1', 'unit_price' => '1']], 'costs' => [['code' => 'F', 'amount' => '5', 'basis' => 'volume']]])
            ->assertUnprocessable();                                                    // volume basis but no volume entered
        $this->as($this->userWith(['sales_rep'], [$this->warehouse]))->postJson('/api/shipments/preview', ['currency' => 'USD', 'exchange_rate' => '9.5', 'lines' => [['quantity' => '1', 'unit_price' => '1']]])->assertForbidden();
    }
}
