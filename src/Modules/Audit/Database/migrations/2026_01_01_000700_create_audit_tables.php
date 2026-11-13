<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->string('occurred_at', 19);          // stored exactly as hashed
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 80);
            $t->string('subject_type', 40)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->string('request_id', 64)->nullable();
            $t->text('data');                       // canonical JSON (text, not jsonb, so bytes are preserved)
            $t->char('prev_hash', 64);
            $t->char('hash', 64)->unique();
            $t->index(['subject_type', 'subject_id']);
            $t->index(['user_id', 'id']);
            $t->index('action');
        });

        Schema::create('audit_chain_head', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->char('last_hash', 64);
        });
        DB::table('audit_chain_head')->insert(['id' => 1, 'last_hash' => str_repeat('0', 64)]);

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION erp_forbid_audit_mutation() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'audit_logs is append-only'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION erp_forbid_audit_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;");
            DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');
        Schema::dropIfExists('audit_logs');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS erp_forbid_audit_mutation()');
        }
    }
};
