<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 80)->unique();
            $t->string('currency', 3);
            $t->bigInteger('exchange_rate');         // x10^4: base units per 1 invoice-currency unit
            $t->string('status', 16)->default('open');
            $t->timestamps();
        });

        Schema::create('shipment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained('shipments');
            $t->foreignId('product_id')->constrained('products');
            $t->bigInteger('quantity');
            $t->bigInteger('unit_price');
            $t->bigInteger('volume_cbm')->default(0);
            $t->bigInteger('weight_kg')->default(0);
            $t->bigInteger('duty_rate')->default(0); // x10^4, 0.05 = 500
        });

        Schema::create('shipment_cost_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained('shipments');
            $t->string('code', 40);
            $t->bigInteger('amount');                // base currency x10^4
            $t->string('basis', 16);
            $t->boolean('in_customs_value')->default(false);
        });

        Schema::create('landed_cost_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_id')->constrained('shipments');
            $t->foreignId('shipment_line_id')->constrained('shipment_lines');
            $t->string('component', 40);
            $t->bigInteger('amount');
            $t->index(['shipment_id', 'shipment_line_id']);
        });
    }

    public function down(): void
    {
        foreach (['landed_cost_allocations', 'shipment_cost_items', 'shipment_lines', 'shipments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
