<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->boolean('has_expiry')->default(false));   // a product either has an expiry date or it does not
        DB::table('products')->whereIn('id', DB::table('stock_lots')->whereNotNull('expiry_date')->select('product_id'))->update(['has_expiry' => true]);
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('has_expiry'));
    }
};
