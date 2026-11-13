<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name');
            $t->string('type', 16)->default('main'); // main | branch | transit | returns | damaged
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 64)->unique();
            $t->string('name');
            $t->string('base_uom', 16)->default('PCS');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('stock_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->string('type', 24);
            $t->smallInteger('direction');
            $t->bigInteger('quantity');              // x10^4, always > 0
            $t->bigInteger('unit_cost');             // x10^4
            $t->bigInteger('total_cost');            // x10^4
            $t->bigInteger('balance_qty_after');
            $t->bigInteger('balance_value_after');
            $t->string('reference', 120);
            $t->string('lot_no', 64)->nullable();
            $t->string('idempotency_key', 160)->nullable()->unique();
            $t->dateTime('occurred_at');
            $t->dateTime('created_at');
            $t->index(['product_id', 'warehouse_id', 'id']);
            $t->index('reference');
        });

        Schema::create('stock_balances', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->bigInteger('on_hand')->default(0);
            $t->bigInteger('reserved')->default(0);
            $t->bigInteger('value')->default(0);
            $t->dateTime('updated_at')->nullable();
            $t->primary(['product_id', 'warehouse_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products');
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->bigInteger('quantity');
            $t->string('reference', 120);
            $t->string('status', 16)->default('active');
            $t->dateTime('expires_at')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'warehouse_id', 'status']);
        });

        $this->guardLedgerAtDatabaseLevel();
    }

    public function down(): void
    {
        foreach (['stock_reservations', 'stock_balances', 'stock_moves', 'products', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS erp_forbid_ledger_mutation()');
        }
    }

    /** The ledger is append-only for everybody, including raw SQL users, and balances can never go negative. */
    private function guardLedgerAtDatabaseLevel(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION erp_forbid_ledger_mutation() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'stock_moves is an append-only ledger'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER stock_moves_immutable BEFORE UPDATE OR DELETE ON stock_moves
                    FOR EACH ROW EXECUTE FUNCTION erp_forbid_ledger_mutation();
                ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_non_negative
                    CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand AND value >= 0);
                ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_qty_positive CHECK (quantity > 0);
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER stock_moves_no_update BEFORE UPDATE ON stock_moves BEGIN SELECT RAISE(ABORT, 'stock_moves is an append-only ledger'); END;");
            DB::unprepared("CREATE TRIGGER stock_moves_no_delete BEFORE DELETE ON stock_moves BEGIN SELECT RAISE(ABORT, 'stock_moves is an append-only ledger'); END;");
        }
    }
};
