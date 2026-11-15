<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company's own details, edited by the administrator on screen. Empty fields fall back to the .env values.
        Schema::create('company_profile', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();   // always 1: a single row
            $t->string('name', 200)->nullable();
            $t->string('tax_id', 60)->nullable();
            $t->string('commercial_register', 60)->nullable();
            $t->string('address', 250)->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('email', 190)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        // Each branch's own details: printed on the invoices it issues.
        Schema::table('warehouses', function (Blueprint $t) {
            $t->string('city', 120)->nullable();
            $t->string('address', 250)->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('commercial_register', 60)->nullable();   // a branch may have its own registration
            $t->unsignedBigInteger('manager_id')->nullable();     // employees.id
            $t->string('notes', 250)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn(['city', 'address', 'phone', 'commercial_register', 'manager_id', 'notes']));
        Schema::dropIfExists('company_profile');
    }
};
