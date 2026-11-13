<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('accounts')->where('code', '1100')->update(['name' => 'Bank - unspecified account']);
        if (! DB::table('accounts')->where('code', '1110')->exists()) {
            DB::table('accounts')->insert(['code' => '1110', 'name' => 'Cash on hand', 'type' => 'asset', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Each bank account is its own ledger account (1120, 1121, ...), so every transfer lands in the bank it really went to.
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('account_number', 60)->nullable();
            $t->string('currency', 3);
            $t->string('gl_account', 16)->unique();
            $t->boolean('active')->default(true);
            $t->dateTime('created_at');
            $t->foreign('gl_account')->references('code')->on('accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
