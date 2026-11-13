<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot (batch) tracking with per-lot cost and expiry. Apply before go-live: it assumes an empty ledger. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_moves', function (Blueprint $t) {
            $t->date('expiry_date')->nullable();
        });

        Schema::create('stock_lots', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->string('lot_no', 64);
            $t->date('expiry_date')->nullable();
            $t->dateTime('received_at');
            $t->bigInteger('on_hand')->default(0);
            $t->bigInteger('value')->default(0);
            $t->primary(['product_id', 'warehouse_id', 'lot_no']);
            $t->index(['expiry_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_non_negative CHECK (on_hand >= 0 AND value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
        Schema::table('stock_moves', fn (Blueprint $t) => $t->dropColumn('expiry_date'));
    }
};
