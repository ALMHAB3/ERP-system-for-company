<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which lots (hence which shipments) each sold line came from: the basis of per-shipment profitability.
        Schema::create('sales_issue_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_line_id')->constrained('sales_order_lines');
            $t->unsignedBigInteger('stock_move_id');
            $t->string('lot_no', 64);
            $t->bigInteger('quantity');
            $t->bigInteger('cost');
            $t->index('lot_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_issue_lines');
    }
};
