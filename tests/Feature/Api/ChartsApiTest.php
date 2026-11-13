<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Domain\Fixed;

final class ChartsApiTest extends ApiTestCase
{
    public function test_chart_series_have_twelve_months_and_hide_costs_without_permission(): void
    {
        app(InventoryLedger::class)->record(new StockMoveData($this->product, $this->warehouse, MoveType::Receipt, Fixed::parse('10'), Fixed::parse('5'), 'g', lotNo: 'L1', expiryDate: new \DateTimeImmutable('+20 days')));
        $buyer = $this->userWith(['purchasing_manager']);     // has reports.view and cost visibility
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);
        $gm = $this->userWith(['general_manager']);

        $c = $this->as($buyer)->getJson('/api/reports/charts')->assertOk()->json();
        $this->assertCount(12, $c['months']);
        $this->assertCount(12, $c['revenue']);
        $this->assertEquals(50.0, $c['expiry_buckets']['0-30']);
        $this->assertSame('MAIN', $c['inventory_by_warehouse'][0]['label']);
        $this->assertArrayHasKey('profit', $c);

        $this->as($rep)->getJson('/api/reports/charts')->assertForbidden();
        $this->assertArrayHasKey('cogs', $this->as($gm)->getJson('/api/reports/charts')->json());   // general manager sees costs
        $this->as($buyer)->getJson('/api/reports/forecast?product_id='.$this->product)->assertOk()->assertJsonStructure(['history', 'history_labels', 'forecast_labels', 'forecast']);
    }
}
