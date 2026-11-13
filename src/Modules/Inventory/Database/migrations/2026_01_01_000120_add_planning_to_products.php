<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedSmallInteger('lead_time_days')->default(45);   // order-to-warehouse time, drives reorder points
            $t->unsignedSmallInteger('cover_days')->default(30);       // how many days beyond lead time one order should cover
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['lead_time_days', 'cover_days']));
    }
};
