<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->string('code', 16)->primary();
            $t->string('name');
            $t->string('type', 16); // asset | liability | equity | revenue | expense
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->string('description');
            $t->string('source_type', 40);
            $t->string('source_id', 64);
            $t->string('rule', 60);
            $t->unsignedBigInteger('reverses_entry_id')->nullable();
            $t->dateTime('posted_at');
            $t->dateTime('created_at');
            $t->unique(['source_type', 'source_id', 'rule']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained('journal_entries');
            $t->string('account_code', 16);
            $t->bigInteger('debit')->default(0);   // x10^4, base currency
            $t->bigInteger('credit')->default(0);
            $t->string('memo')->nullable();
            $t->foreign('account_code')->references('code')->on('accounts');
            $t->index('account_code');
        });

        $now = now();
        DB::table('accounts')->insert(array_map(fn ($a) => $a + ['active' => true, 'created_at' => $now, 'updated_at' => $now], [
            ['code' => '1100', 'name' => 'Bank and cash', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'Accounts receivable', 'type' => 'asset'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset'],
            ['code' => '2100', 'name' => 'Goods received not invoiced (GR/IR)', 'type' => 'liability'],
            ['code' => '2200', 'name' => 'VAT payable', 'type' => 'liability'],
            ['code' => '4000', 'name' => 'Sales revenue', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Inventory variance', 'type' => 'expense'],
        ]));

        // Posted journals are immutable.
        $driver = DB::getDriverName();
        foreach (['journal_entries', 'journal_lines'] as $table) {
            if ($driver === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION erp_forbid_journal_mutation() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'posted journals are immutable: post a reversal'; END; \$\$ LANGUAGE plpgsql;");
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION erp_forbid_journal_mutation();");
            } elseif ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'posted journals are immutable: post a reversal'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'posted journals are immutable: post a reversal'); END;");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS erp_forbid_journal_mutation()');
        }
    }
};
