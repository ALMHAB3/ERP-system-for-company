<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->unsignedSmallInteger('terms_days')->default(0);   // 0 = due on invoice
        });

        Schema::table('sales_invoices', function (Blueprint $t) {
            $t->date('due_date')->nullable();
            $t->bigInteger('paid')->default(0);       // receipts applied
            $t->bigInteger('credited')->default(0);   // credit notes applied
        });

        Schema::create('customer_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers');
            $t->date('received_on');
            $t->bigInteger('amount');
            $t->string('method', 16);       // cash | bank | cheque
            $t->string('reference', 120);
            $t->dateTime('created_at');
        });

        Schema::create('receipt_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_receipt_id')->constrained('customer_receipts');
            $t->foreignId('sales_invoice_id')->constrained('sales_invoices');
            $t->bigInteger('amount');
        });

        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('sales_invoice_id')->constrained('sales_invoices');
            $t->bigInteger('net');
            $t->bigInteger('tax');
            $t->bigInteger('gross');
            $t->string('reason', 200);
            $t->dateTime('created_at');
        });

        Schema::create('sales_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('credit_note_id')->constrained('credit_notes');
            $t->foreignId('sales_order_line_id')->constrained('sales_order_lines');
            $t->unsignedBigInteger('sales_issue_line_id');
            $t->bigInteger('quantity');
            $t->bigInteger('cost');
        });
    }

    public function down(): void
    {
        foreach (['sales_return_lines', 'credit_notes', 'receipt_allocations', 'customer_receipts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn(['due_date', 'paid', 'credited']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('terms_days'));
    }
};
