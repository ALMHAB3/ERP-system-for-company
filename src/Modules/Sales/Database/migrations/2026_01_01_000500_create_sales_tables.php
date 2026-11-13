<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('category', 16); // wholesale | retail | direct
            $t->bigInteger('credit_limit')->default(0);
            $t->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->string('category', 16)->nullable(); // null = default list
            $t->bigInteger('price');
            $t->unique(['product_id', 'category']);
        });

        Schema::create('contract_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('product_id')->constrained('products');
            $t->bigInteger('price');
            $t->unique(['customer_id', 'product_id']);
        });

        Schema::create('sales_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->string('status', 16);
            $t->timestamps();
        });

        Schema::create('sales_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->constrained('sales_orders');
            $t->foreignId('product_id')->constrained('products');
            $t->bigInteger('quantity');
            $t->bigInteger('list_price');
            $t->bigInteger('discount_rate');
            $t->bigInteger('unit_price');
            $t->unsignedBigInteger('reservation_id')->nullable();
            $t->bigInteger('cost_of_goods')->nullable();
        });

        Schema::create('sales_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('sales_order_id')->constrained('sales_orders');
            $t->bigInteger('net');
            $t->bigInteger('tax');
            $t->bigInteger('gross');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        foreach (['sales_invoices', 'sales_order_lines', 'sales_orders', 'contract_prices', 'price_list_items', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
