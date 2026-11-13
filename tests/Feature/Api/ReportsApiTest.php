<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

final class ReportsApiTest extends ApiTestCase
{
    public function test_reports_need_permission_and_cost_reports_need_cost_visibility(): void
    {
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $buyer = $this->userWith(['purchasing_manager']);
        $finance = $this->userWith(['finance_manager']);

        $this->as($clerk)->getJson('/api/reports/dashboard')->assertForbidden();
        $this->as($buyer)->getJson('/api/reports/dashboard')->assertOk()->assertJsonStructure(['inventory_value', 'receivables_open', 'payables_open', 'shipments', 'expiring_60d_lots']);
        $this->as($finance)->getJson('/api/reports/shipment-profitability')->assertOk()->assertJsonPath('data', []);
        $this->as($finance)->getJson('/api/reports/forecast?product_id='.$this->product.'&horizon=2')->assertOk()->assertJsonPath('method', 'none')->assertJsonCount(2, 'forecast');
        $this->as($finance)->getJson('/api/reports/reorder-suggestions')->assertOk()->assertJsonPath('data', []);
        $this->as($finance)->getJson('/api/reports/expiring?days=0')->assertUnprocessable();
    }
}
