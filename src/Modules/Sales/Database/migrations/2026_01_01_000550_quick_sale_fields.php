<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->string('customer_name', 200)->nullable();          // typed name of a cash / transfer buyer who is not registered
            $t->unsignedBigInteger('created_by')->nullable();      // the sales representative who made the sale
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['customer_name', 'created_by']));
    }
};
