<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

/** The simple way to enter a shipment: items, then each expense as one amount; customs is an amount, not a rate. */
final class SimpleShipmentApiTest extends ApiTestCase
{
    public function test_customs_is_an_amount_spread_with_the_other_expenses_and_lots_are_automatic(): void
    {
        $buyer = $this->userWith(['purchasing_manager']);
        $finance = $this->userWith(['finance_manager']);
        $second = $this->makeProduct('SKU-2');

        // 100 x 10 USD and 50 x 20 USD at rate 5: each line is worth 5 000 dinar
        $id = $this->as($buyer)->postJson('/api/shipments', [
            'reference' => 'CONT-SIMPLE', 'currency' => 'USD', 'exchange_rate' => '5',
            'lines' => [['product_id' => $this->product, 'quantity' => '100', 'unit_price' => '10'], ['product_id' => $second, 'quantity' => '50', 'unit_price' => '20']],
            'costs' => [
                ['code' => 'FREIGHT', 'amount' => '800', 'basis' => 'value'],
                ['code' => 'CUSTOMS', 'amount' => '1200', 'basis' => 'value'],
                ['code' => 'CLEARANCE', 'amount' => '300', 'basis' => 'quantity'],
            ],
        ])->assertCreated()->json('id');

        $r = $this->as($finance)->postJson("/api/shipments/{$id}/finalize")->assertOk()->json();
        $this->assertSame('12300.0000', $r['total']);                       // 10 000 goods + 800 + 1 200 + 300, nothing computed from a rate
        // equal values share freight and customs equally; clearance follows the quantities (100 : 50)
        $this->assertSame('62.0000', $r['lines'][0]['unit_cost']);          // (5 000 + 400 + 600 + 200) / 100
        $this->assertSame('122.0000', $r['lines'][1]['unit_cost']);         // (5 000 + 400 + 600 + 100) / 50

        // no supplier lot number was given: receiving still works and the system numbers the lots itself
        $this->as($this->userWith(['warehouse_clerk'], [$this->warehouse]))->postJson("/api/shipments/{$id}/receive", ['warehouse_id' => $this->warehouse])->assertOk();
        $lots = collect($this->as($finance)->getJson('/api/inventory/lots')->assertOk()->json('data'));
        $this->assertCount(2, $lots);
        $this->assertTrue($lots->every(fn ($l) => ($l['lot_no'] ?? '') !== ''));
        $this->assertSame('62.0000', $lots->firstWhere('sku', 'SKU-1')['unit_cost']);
    }
}
