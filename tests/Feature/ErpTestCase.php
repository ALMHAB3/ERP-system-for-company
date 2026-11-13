<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class ErpTestCase extends TestCase
{
    use RefreshDatabase;

    protected int $warehouse;

    protected int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = $this->makeWarehouse('MAIN');
        $this->product = $this->makeProduct('SKU-1');
    }

    protected function makeWarehouse(string $code): int
    {
        return DB::table('warehouses')->insertGetId(['code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function makeProduct(string $sku): int
    {
        return DB::table('products')->insertGetId(['sku' => $sku, 'name' => $sku, 'created_at' => now(), 'updated_at' => now()]);
    }
}
