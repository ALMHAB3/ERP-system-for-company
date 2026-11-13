<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_lines', function (Blueprint $t) {
            $t->string('lot_no', 64)->nullable();
            $t->date('expiry_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_lines', fn (Blueprint $t) => $t->dropColumn(['lot_no', 'expiry_date']));
    }
};
