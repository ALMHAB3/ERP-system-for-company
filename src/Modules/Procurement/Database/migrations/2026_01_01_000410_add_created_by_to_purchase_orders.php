<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('created_by')->nullable()->after('required_approval_level');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('created_by'));
    }
};
