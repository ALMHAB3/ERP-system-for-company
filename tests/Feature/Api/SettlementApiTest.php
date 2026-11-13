<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

final class SettlementApiTest extends ApiTestCase
{
    public function test_final_cost_settlement_through_the_api(): void
    {
        $buyer = $this->userWith(['purchasing_manager']);
        $finance = $this->userWith(['finance_manager']);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'S', 'currency' => 'USD', 'terms_days' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->as($buyer)->postJson('/api/shipments', ['reference' => 'AP-1', 'currency' => 'USD', 'exchange_rate' => '4',
            'lines' => [['product_id' => $this->product, 'quantity' => '100', 'unit_price' => '10', 'duty_rate' => '0']]])->assertCreated()->json('id');
        $this->as($finance)->postJson("/api/shipments/{$id}/finalize")->assertOk();
        $this->as($clerk)->postJson("/api/shipments/{$id}/receive", ['warehouse_id' => $this->warehouse])->assertOk();    // booked 4,000

        $this->as($finance)->getJson('/api/payables/settlement?shipment_reference=AP-1')->assertUnprocessable();            // nothing invoiced yet
        $inv = $this->as($finance)->postJson('/api/payables/invoices', ['supplier_id' => $supplier, 'number' => 'X1', 'invoice_date' => '2026-05-01', 'currency' => 'USD', 'exchange_rate' => '4', 'subtotal' => '1000', 'kind' => 'goods', 'shipment_reference' => 'AP-1'])->assertCreated()->json('id');
        $this->as($finance)->postJson('/api/payables/payments', ['supplier_id' => $supplier, 'paid_on' => '2026-05-20', 'currency' => 'USD', 'exchange_rate' => '4.2', 'method' => 'bank', 'reference' => 'T1', 'allocations' => [['invoice_id' => $inv, 'amount' => '1000']]])->assertCreated();

        $this->as($buyer)->getJson('/api/payables/settlement?shipment_reference=AP-1')->assertForbidden();                  // finance only
        $p = $this->as($finance)->getJson('/api/payables/settlement?shipment_reference=AP-1')->assertOk()
            ->assertJsonPath('delta', '200.0000')->assertJsonPath('final_total', '4200.0000')->assertJsonPath('lines.0.final_unit_cost', '42.0000')->json();
        $this->assertFalse($p['settled']);
        $this->assertSame(0, DB::table('shipment_cost_settlements')->count());

        $this->as($finance)->postJson('/api/payables/settlement', ['shipment_reference' => 'AP-1'])->assertCreated()->assertJsonPath('settled', true);
        $this->as($finance)->postJson('/api/payables/settlement', ['shipment_reference' => 'AP-1'])->assertUnprocessable()->assertJsonPath('code', 'DomainException');
        $this->assertTrue(DB::table('audit_logs')->where('action', 'stock.revaluation_up')->exists());
        $this->as($finance)->getJson('/api/inventory/balances')->assertOk()->assertJsonPath('data.0.average_cost', '42.0000');
    }
}
