<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_moves DROP CONSTRAINT IF EXISTS stock_moves_qty_positive');
            DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_qty_non_negative CHECK (quantity >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_moves DROP CONSTRAINT IF EXISTS stock_moves_qty_non_negative');
            DB::statement('ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_qty_positive CHECK (quantity > 0)');
        }
    }
};
