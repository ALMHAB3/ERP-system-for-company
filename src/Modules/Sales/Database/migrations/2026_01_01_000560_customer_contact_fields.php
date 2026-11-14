<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('phone', 40)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('city', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['phone', 'email', 'city']));
    }
};
