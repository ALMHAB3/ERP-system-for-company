<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t) {
            $t->string('code', 3)->primary();
            $t->string('name');
            $t->boolean('active')->default(true);
        });

        // Rate = how many BASE-currency units one unit of this currency buys, x10^4. History is never rewritten:
        // a new rate for the same day is refused; invoices keep the rate they were booked with.
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->string('currency', 3);
            $t->date('rate_date');
            $t->bigInteger('rate');
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->dateTime('created_at');
            $t->foreign('currency')->references('code')->on('currencies');
            $t->unique(['currency', 'rate_date']);
        });

        foreach (config('erp.currencies') as $code => $name) {
            DB::table('currencies')->insert(['code' => $code, 'name' => $name, 'active' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
