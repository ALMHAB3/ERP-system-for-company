<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->string('supplier');
            $t->string('currency', 3);
            $t->bigInteger('exchange_rate');
            $t->string('status', 24)->default('draft');
            $t->unsignedSmallInteger('required_approval_level')->default(1);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders');
            $t->foreignId('product_id')->constrained('products');
            $t->bigInteger('quantity');
            $t->bigInteger('unit_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
