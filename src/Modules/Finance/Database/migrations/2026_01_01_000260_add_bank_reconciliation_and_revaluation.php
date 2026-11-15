<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A ledger line may also carry its amount in a foreign currency (signed: + debit, - credit).
        // Needed for foreign-currency bank accounts: their statements and their revaluation are in that currency.
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->string('currency', 3)->nullable();
            $t->bigInteger('amount_currency')->nullable();
        });

        foreach ([['5210', 'Unrealised exchange differences', 'expense'], ['5600', 'Bank charges', 'expense']] as [$code, $name, $type]) {
            if (! DB::table('accounts')->where('code', $code)->exists()) {
                DB::table('accounts')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Bank statements, entered from the paper/PDF statement. Amounts are in the bank account's own currency.
        Schema::create('bank_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_account_id')->constrained('bank_accounts');
            $t->string('reference', 60);
            $t->date('statement_date');
            $t->bigInteger('opening_balance');
            $t->bigInteger('closing_balance');
            $t->string('status', 12)->default('open');   // open | reconciled
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('reconciled_by')->nullable();
            $t->dateTime('reconciled_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['bank_account_id', 'reference']);
        });
        // Each statement line is matched to exactly one ledger line of the bank's account; a ledger line is matched once.
        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_statement_id')->constrained('bank_statements');
            $t->date('line_date');
            $t->string('description', 200);
            $t->bigInteger('amount');                    // + money in, - money out
            $t->unsignedBigInteger('journal_line_id')->nullable()->unique();
            $t->unsignedBigInteger('matched_by')->nullable();
            $t->dateTime('matched_at')->nullable();
            $t->foreign('journal_line_id')->references('id')->on('journal_lines');
            $t->index('bank_statement_id');
        });

        // Period-end revaluation of open foreign balances at the closing rate. Each run is reversed the next day,
        // so the realised difference booked at payment time is still measured against the original rate.
        Schema::create('fx_revaluations', function (Blueprint $t) {
            $t->id();
            $t->date('as_of')->unique();
            $t->date('reverse_on');
            $t->bigInteger('net');                       // + net loss, - net gain
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');
        });
        Schema::create('fx_revaluation_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fx_revaluation_id')->constrained('fx_revaluations');
            $t->string('account_code', 16);
            $t->string('subject', 160);
            $t->string('currency', 3);
            $t->bigInteger('rate');
            $t->bigInteger('foreign_balance');
            $t->bigInteger('carrying_base');
            $t->bigInteger('revalued_base');
            $t->bigInteger('difference');                // change to the account's DEBIT balance
        });
    }

    public function down(): void
    {
        foreach (['fx_revaluation_lines', 'fx_revaluations', 'bank_statement_lines', 'bank_statements'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
