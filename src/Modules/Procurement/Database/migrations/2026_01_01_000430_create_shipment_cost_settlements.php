<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_cost_settlements', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_reference', 120)->unique();     // one settlement per shipment
            $t->bigInteger('fx_difference');                    // payment FX difference reclassified (ex-VAT)
            $t->bigInteger('cleared_variance');                 // GR/IR variance reclassified
            $t->bigInteger('delta');                            // net change of the shipment's total cost
            $t->bigInteger('inventory_part');
            $t->bigInteger('cogs_part');
            $t->unsignedBigInteger('settled_by')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('shipment_settlement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shipment_cost_settlement_id')->constrained('shipment_cost_settlements');
            $t->unsignedBigInteger('product_id');
            $t->string('lot_no', 64);
            $t->bigInteger('delta');
            $t->bigInteger('inventory_part');
            $t->bigInteger('cogs_part');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_settlement_lines');
        Schema::dropIfExists('shipment_cost_settlements');
    }
};
