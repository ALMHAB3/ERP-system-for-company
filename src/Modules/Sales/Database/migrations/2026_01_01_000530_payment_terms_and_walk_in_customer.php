<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->boolean('is_walk_in')->default(false);   // the "cash customer": anonymous buyers, never on credit
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->string('payment_method', 12)->default('credit');   // cash | transfer | credit
            $t->string('cash_account', 16)->nullable();            // ledger account the money goes to (cash till / chosen bank)
            $t->string('payment_reference', 120)->nullable();
        });
        Schema::table('customer_receipts', function (Blueprint $t) {
            $t->string('cash_account', 16)->nullable();
        });

        DB::table('customers')->insert([
            'name' => 'Cash customer - عميل نقدي', 'category' => 'direct', 'credit_limit' => 0, 'terms_days' => 0, 'is_walk_in' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('customer_receipts', fn (Blueprint $t) => $t->dropColumn('cash_account'));
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['payment_method', 'cash_account', 'payment_reference']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('is_walk_in'));
    }
};
