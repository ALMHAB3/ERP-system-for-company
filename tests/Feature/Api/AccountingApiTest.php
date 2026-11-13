<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

final class AccountingApiTest extends ApiTestCase
{
    public function test_supplier_billing_and_payment_through_the_api_with_role_limits(): void
    {
        $finance = $this->userWith(['finance_manager']);
        $buyer = $this->userWith(['purchasing_manager']);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);

        $supplier = $this->as($buyer)->postJson('/api/payables/suppliers', ['name' => 'Guangzhou Co', 'currency' => 'USD', 'terms_days' => 45, 'country' => 'cn'])->assertCreated()->json('id');
        $this->as($clerk)->postJson('/api/payables/suppliers', ['name' => 'X', 'currency' => 'USD'])->assertForbidden();

        $po = $this->as($buyer)->postJson('/api/purchase-orders', [
            'number' => 'PO-77', 'supplier' => 'Guangzhou Co', 'currency' => 'SAR', 'exchange_rate' => '1',
            'lines' => [['product_id' => $this->product, 'quantity' => '100', 'unit_price' => '5']],
        ])->assertCreated()->json('id');
        $this->as($buyer)->postJson("/api/purchase-orders/{$po}/submit")->assertOk();
        $this->as($this->userWith(['finance_manager']))->postJson("/api/purchase-orders/{$po}/approve")->assertOk();
        $this->as($clerk)->postJson("/api/purchase-orders/{$po}/receive", ['warehouse_id' => $this->warehouse])->assertOk();

        $bill = [
            'supplier_id' => $supplier, 'number' => 'GZ-1001', 'invoice_date' => '2026-05-01', 'currency' => 'USD', 'exchange_rate' => '3.75',
            'subtotal' => '133.3333', 'tax' => '0', 'kind' => 'goods', 'po_number' => 'PO-77',
        ];
        $this->as($buyer)->postJson('/api/payables/invoices', $bill)->assertForbidden();           // recording bills is finance's job
        $id = $this->as($finance)->postJson('/api/payables/invoices', $bill)->assertCreated()->json('id');
        $this->as($finance)->postJson('/api/payables/invoices', $bill)->assertUnprocessable()->assertJsonPath('code', 'DuplicateInvoice');

        $this->as($finance)->getJson('/api/payables/goods-received?reference=po:PO-77')->assertOk()->assertJsonPath('receipts', '500.0000');
        $this->as($finance)->getJson('/api/payables/invoices?open=1')->assertOk()->assertJsonPath('data.0.balance', '133.3333');

        $this->as($finance)->postJson('/api/payables/payments', [
            'supplier_id' => $supplier, 'paid_on' => '2026-05-20', 'currency' => 'USD', 'exchange_rate' => '3.75', 'method' => 'bank', 'reference' => 'TT-1',
            'allocations' => [['invoice_id' => $id, 'amount' => '133.3333']],
        ])->assertCreated();
        $this->as($finance)->getJson('/api/payables/invoices?open=1')->assertOk()->assertJsonCount(0, 'data');
        $this->as($finance)->getJson('/api/payables/ageing')->assertOk()->assertJsonPath('buckets.current', '0.0000');
        $this->assertTrue(DB::table('audit_logs')->where('action', 'http.post')->where('user_id', $finance->id)->exists());
    }

    public function test_override_credit_and_returns_need_their_own_permissions(): void
    {
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);
        $customer = DB::table('customers')->insertGetId(['name' => 'C', 'category' => 'retail', 'credit_limit' => 1 * 10000, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($rep)->postJson('/api/sales-orders', [
            'customer_id' => $customer, 'warehouse_id' => $this->warehouse, 'override_credit' => true, 'lines' => [['product_id' => $this->product, 'quantity' => '1']],
        ])->assertForbidden();
        $this->as($rep)->postJson('/api/receivables/receipts', ['customer_id' => $customer, 'received_on' => '2026-01-01', 'method' => 'cash', 'reference' => 'r', 'allocations' => [['invoice_id' => 1, 'amount' => '1']]])->assertForbidden();
        $this->as($rep)->getJson('/api/receivables/ageing')->assertForbidden();
    }
}
