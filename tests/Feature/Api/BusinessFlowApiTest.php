<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

/** The import -> sale cycle driven only through the HTTP API, each step by the role that owns it. */
final class BusinessFlowApiTest extends ApiTestCase
{
    public function test_import_to_invoice_through_the_api(): void
    {
        $buyer = $this->userWith(['purchasing_manager']);
        $finance = $this->userWith(['finance_manager']);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);

        $customer = $this->as($buyer)->postJson('/api/customers', ['name' => 'Al Noor', 'category' => 'wholesale', 'phone' => '0912345678', 'city' => 'طرابلس'])->assertCreated()->json('id');
        $this->as($buyer)->putJson('/api/prices', ['product_id' => $this->product, 'category' => 'wholesale', 'price' => '20'])->assertNoContent();

        $shipment = $this->as($buyer)->postJson('/api/shipments', [
            'reference' => 'CONT-9', 'currency' => 'usd', 'exchange_rate' => '1',
            'lines' => [['product_id' => $this->product, 'quantity' => '1000', 'unit_price' => '10', 'volume_cbm' => '10', 'weight_kg' => '2000', 'duty_rate' => '0.05']],
            'costs' => [
                ['code' => 'FREIGHT', 'amount' => '1000', 'basis' => 'volume', 'in_customs_value' => true],
                ['code' => 'INSURANCE', 'amount' => '100', 'basis' => 'value', 'in_customs_value' => true],
                ['code' => 'CLEARANCE', 'amount' => '600', 'basis' => 'weight'],
            ],
        ])->assertCreated()->json('id');

        $this->as($buyer)->postJson("/api/shipments/{$shipment}/finalize")->assertForbidden();   // costing is finance's job
        $this->as($finance)->postJson("/api/shipments/{$shipment}/finalize")
            ->assertOk()->assertJsonPath('total', '12255.0000')->assertJsonPath('lines.0.unit_cost', '12.2550');
        $this->as($clerk)->postJson("/api/shipments/{$shipment}/receive", ['warehouse_id' => $this->warehouse])->assertOk();

        $order = $this->as($rep)->postJson('/api/sales-orders', [
            'customer_id' => $customer, 'warehouse_id' => $this->warehouse, 'lines' => [['product_id' => $this->product, 'quantity' => '100']],
        ])->assertCreated()->assertJsonPath('lines.0.unit_price', '19.4000')->json('id');

        $this->as($rep)->postJson('/api/sales-orders', [
            'customer_id' => $customer, 'warehouse_id' => $this->warehouse, 'lines' => [['product_id' => $this->product, 'quantity' => '901']],
        ])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->as($clerk)->postJson("/api/sales-orders/{$order}/ship")->assertOk()->assertJsonPath('status', 'shipped');
        $this->as($rep)->postJson("/api/sales-orders/{$order}/invoice")->assertForbidden();
        $this->as($finance)->postJson("/api/sales-orders/{$order}/invoice")->assertCreated();
        $this->as($finance)->postJson("/api/sales-orders/{$order}/invoice")->assertUnprocessable()->assertJsonPath('code', 'InvalidOrderState');

        $tb = collect($this->as($finance)->getJson('/api/finance/trial-balance')->assertOk()->json('data'))->keyBy('account_code');
        $this->assertSame('1225.5000', $tb['5000']['debit']);
        $this->assertSame('1940.0000', $tb['4000']['credit']);

        $this->as($finance)->getJson('/api/audit-logs/verify')->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'shipment.costed')->where('user_id', $finance->id)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'stock.issue')->where('user_id', $clerk->id)->exists());
    }

    public function test_purchase_order_approval_enforces_separation_of_duties_and_levels(): void
    {
        $buyer = $this->userWith(['purchasing_manager']);
        $otherBuyer = $this->userWith(['purchasing_manager']);
        $gm = $this->userWith(['general_manager']);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);

        $po = $this->as($buyer)->postJson('/api/purchase-orders', [
            'number' => 'PO-1', 'supplier' => 'Local Co', 'currency' => 'LYD', 'exchange_rate' => '1',
            'lines' => [['product_id' => $this->product, 'quantity' => '5000', 'unit_price' => '25']],   // 125,000 -> level 3
        ])->assertCreated()->assertJsonPath('required_approval_level', 3)->json('id');
        $this->as($buyer)->postJson("/api/purchase-orders/{$po}/submit")->assertOk();

        $this->as($buyer)->postJson("/api/purchase-orders/{$po}/approve")
            ->assertUnprocessable()->assertJsonPath('message', 'The creator of a purchase order cannot approve it.');
        $this->as($otherBuyer)->postJson("/api/purchase-orders/{$po}/approve")
            ->assertUnprocessable()->assertJsonPath('message', 'Approval level 3 required, approver has 1.');
        $this->as($clerk)->postJson("/api/purchase-orders/{$po}/receive", ['warehouse_id' => $this->warehouse])->assertUnprocessable(); // not approved yet

        $this->as($gm)->postJson("/api/purchase-orders/{$po}/approve")->assertOk();
        $this->as($clerk)->postJson("/api/purchase-orders/{$po}/receive", ['warehouse_id' => $this->warehouse])->assertOk();

        $this->as($clerk)->getJson('/api/inventory/balances')->assertJsonPath('data.0.on_hand', '5000.0000');
    }

    public function test_idempotency_key_prevents_double_adjustments_on_retry(): void
    {
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $body = ['product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'direction' => 'in', 'quantity' => '7', 'unit_cost' => '1', 'reason' => 'found'];

        $a = $this->as($clerk)->withHeader('Idempotency-Key', 'retry-key-0001')->postJson('/api/inventory/adjustments', $body)->json('id');
        $b = $this->as($clerk)->withHeader('Idempotency-Key', 'retry-key-0001')->postJson('/api/inventory/adjustments', $body)->json('id');

        $this->assertSame($a, $b);
        $this->as($clerk)->getJson('/api/inventory/balances')->assertJsonPath('data.0.on_hand', '7.0000');
    }

    public function test_decimals_are_validated_strictly(): void
    {
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        foreach (['1.23456', '-5', '1e3', 'abc'] as $bad) {
            $this->as($clerk)->postJson('/api/inventory/adjustments', [
                'product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'direction' => 'out', 'quantity' => $bad, 'reason' => 'x',
            ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
    }
}
