<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

final class CurrencyApiTest extends ApiTestCase
{
    private function shipment(string $currency, ?string $rate, string $ref): array
    {
        return ['reference' => $ref, 'currency' => $currency] + ($rate !== null ? ['exchange_rate' => $rate] : []) + [
            'lines' => [['product_id' => $this->product, 'quantity' => '100', 'unit_price' => '10', 'volume_cbm' => '1', 'weight_kg' => '1', 'duty_rate' => '0']],
        ];
    }

    public function test_the_four_business_currencies_exist_with_dinar_as_base(): void
    {
        $finance = $this->userWith(['finance_manager']);
        $r = $this->as($finance)->getJson('/api/currencies')->assertOk()->assertJsonPath('base', 'LYD')->json('currencies');
        $this->assertSame(['CNY', 'EUR', 'LYD', 'USD'], collect($r)->pluck('code')->sort()->values()->all());
        $this->assertSame('1.0000', collect($r)->firstWhere('code', 'LYD')['rate']);
    }

    public function test_rates_are_entered_by_finance_only_never_overwritten_and_drive_documents(): void
    {
        $finance = $this->userWith(['finance_manager']);
        $buyer = $this->userWith(['purchasing_manager']);

        $this->as($buyer)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '4.85'])->assertForbidden();
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '4.8500', 'date' => '2026-01-01'])->assertCreated();
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '4.9', 'date' => '2026-01-01'])->assertUnprocessable();   // same day: refused
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'CNY', 'rate' => '0.6781'])->assertCreated();
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'LYD', 'rate' => '2'])->assertUnprocessable()->assertJsonPath('message', 'The base currency has no rate.');
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'GBP', 'rate' => '6'])->assertUnprocessable();

        $id = $this->as($buyer)->postJson('/api/shipments', $this->shipment('usd', null, 'S-USD'))->assertCreated()->json('id');   // rate taken from the table
        $this->assertSame(48500, (int) DB::table('shipments')->where('id', $id)->value('exchange_rate'));
        $this->as($buyer)->postJson('/api/shipments', $this->shipment('CNY', null, 'S-CNY'))->assertCreated();
        $this->as($buyer)->postJson('/api/shipments', $this->shipment('EUR', null, 'S-EUR'))->assertUnprocessable();                 // no EUR rate yet
        $this->as($buyer)->postJson('/api/shipments', $this->shipment('EUR', '5.3', 'S-EUR2'))->assertCreated();                       // explicit rate allowed
        $this->as($buyer)->postJson('/api/shipments', $this->shipment('GBP', '6', 'S-GBP'))->assertUnprocessable();
        $this->as($buyer)->postJson('/api/shipments', $this->shipment('LYD', '2', 'S-LYD'))->assertUnprocessable();
        $this->as($buyer)->postJson('/api/shipments', $this->shipment('LYD', null, 'S-LYD2'))->assertCreated();

        $this->as($finance)->getJson('/api/currencies/history?currency=USD')->assertOk()->assertJsonPath('data.0.rate', '4.8500');
        $this->assertTrue(DB::table('audit_logs')->where('action', 'exchange_rate.set')->exists());
        $this->as($finance)->getJson('/api/lookups')->assertOk()->assertJsonPath('currencies.0.code', 'CNY');
    }

    public function test_a_dollar_shipment_is_costed_in_dinar(): void
    {
        $finance = $this->userWith(['finance_manager']);
        $buyer = $this->userWith(['purchasing_manager']);
        $this->as($finance)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '4.85'])->assertCreated();
        $id = $this->as($buyer)->postJson('/api/shipments', $this->shipment('USD', null, 'S-1'))->assertCreated()->json('id');

        $this->as($finance)->postJson("/api/shipments/{$id}/finalize")->assertOk()->assertJsonPath('total', '4850.0000');   // 100 x 10 USD x 4.85
    }
}
