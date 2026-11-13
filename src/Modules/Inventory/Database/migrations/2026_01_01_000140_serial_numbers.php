<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->boolean('tracks_serials')->default(false));

        Schema::create('product_serials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->string('serial', 80);
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->string('status', 12)->default('in_stock');            // in_stock | reserved | sold
            $t->unsignedBigInteger('sales_order_line_id')->nullable();  // set while reserved / after sale
            $t->dateTime('sold_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['product_id', 'serial']);
            $t->index(['product_id', 'warehouse_id', 'status']);
            $t->index('sales_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('tracks_serials'));
    }
};
