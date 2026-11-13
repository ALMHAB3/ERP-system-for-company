<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('country', 2)->nullable();
            $t->string('tax_no', 40)->nullable();
            $t->string('currency', 3);
            $t->unsignedSmallInteger('terms_days')->default(30);
            $t->string('bank_details')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->string('number', 60);
            $t->date('invoice_date');
            $t->date('due_date');
            $t->string('currency', 3);
            $t->bigInteger('exchange_rate');
            $t->bigInteger('subtotal');
            $t->bigInteger('tax');
            $t->bigInteger('base_total');
            $t->string('kind', 16);
            $t->string('receipt_reference', 120)->nullable();
            $t->string('expense_account', 16)->nullable();
            $t->bigInteger('paid')->default(0);
            $t->bigInteger('base_paid')->default(0);
            $t->dateTime('created_at');
            $t->unique(['supplier_id', 'number']);   // the same supplier invoice cannot be booked twice
            $t->index('receipt_reference');
            $t->index('due_date');
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained('suppliers');
            $t->date('paid_on');
            $t->string('currency', 3);
            $t->bigInteger('exchange_rate');
            $t->bigInteger('amount');
            $t->string('method', 16);
            $t->string('reference', 120);
            $t->string('cash_account', 16)->nullable();   // ledger account the money left from
            $t->dateTime('created_at');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_payment_id')->constrained('supplier_payments');
            $t->foreignId('supplier_invoice_id')->constrained('supplier_invoices');
            $t->bigInteger('amount');
            $t->bigInteger('invoice_base');
            $t->bigInteger('cash_base');
        });

        Schema::create('grir_clearings', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_reference', 120)->unique();
            $t->bigInteger('residual');
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        foreach (['grir_clearings', 'supplier_payment_allocations', 'supplier_payments', 'supplier_invoices', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
