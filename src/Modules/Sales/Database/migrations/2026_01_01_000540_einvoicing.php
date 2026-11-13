<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('tax_id', 40)->nullable();
            $t->string('address', 250)->nullable();
        });
        Schema::table('sales_invoices', function (Blueprint $t) {
            $t->string('fingerprint', 64)->nullable();        // SHA-256 sealing this invoice and the one before it
            $t->string('prev_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn(['fingerprint', 'prev_fingerprint']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['tax_id', 'address']));
    }
};
