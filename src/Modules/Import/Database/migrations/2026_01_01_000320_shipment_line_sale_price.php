<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_lines', fn (Blueprint $t) => $t->bigInteger('sale_price')->nullable());   // selling price in the base currency, entered with the shipment
    }

    public function down(): void
    {
        Schema::table('shipment_lines', fn (Blueprint $t) => $t->dropColumn('sale_price'));
    }
};
