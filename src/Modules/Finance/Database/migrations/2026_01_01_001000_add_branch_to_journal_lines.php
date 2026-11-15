<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every ledger line carries the branch it belongs to (null = head office / the whole company).
 * Branch profit and loss, a branch's own trial balance and its cash till are read from this column.
 * Existing lines are attributed to their branch from the document that produced them.
 */
return new class extends Migration
{
    /** source type => SQL returning the branch of entry e (one value, or no row when it cannot be told). */
    private const SOURCES = [
        'stock_move' => 'SELECT m.warehouse_id FROM stock_moves m WHERE m.id = CAST(e.source_id AS INTEGER)',
        'sales_invoice' => 'SELECT o.warehouse_id FROM sales_invoices i JOIN sales_orders o ON o.id = i.sales_order_id WHERE i.id = CAST(e.source_id AS INTEGER)',
        'credit_note' => 'SELECT o.warehouse_id FROM credit_notes n JOIN sales_invoices i ON i.id = n.sales_invoice_id JOIN sales_orders o ON o.id = i.sales_order_id WHERE n.id = CAST(e.source_id AS INTEGER)',
        'customer_receipt' => 'SELECT MIN(o.warehouse_id) FROM receipt_allocations a JOIN sales_invoices i ON i.id = a.sales_invoice_id JOIN sales_orders o ON o.id = i.sales_order_id WHERE a.customer_receipt_id = CAST(e.source_id AS INTEGER) HAVING COUNT(DISTINCT o.warehouse_id) = 1',
        'employee_advance' => 'SELECT em.warehouse_id FROM employee_advances ad JOIN employees em ON em.id = ad.employee_id WHERE ad.id = CAST(e.source_id AS INTEGER)',
    ];

    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->index(['warehouse_id', 'account_code']);
        });

        // Ledger lines are immutable: lift the guard only for this one-time attribution, then put it back.
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER journal_lines_immutable');
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS journal_lines_no_update');
        }
        foreach (self::SOURCES as $type => $branchOf) {
            DB::update("UPDATE journal_lines SET warehouse_id = (SELECT ({$branchOf}) FROM journal_entries e WHERE e.id = journal_lines.journal_entry_id)
                WHERE journal_entry_id IN (SELECT id FROM journal_entries WHERE source_type = ?)", [$type]);
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER journal_lines_immutable');
        } elseif ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER journal_lines_no_update BEFORE UPDATE ON journal_lines BEGIN SELECT RAISE(ABORT, 'posted journals are immutable: post a reversal'); END;");
        }
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->dropIndex(['warehouse_id', 'account_code']);
            $t->dropColumn('warehouse_id');
        });
    }
};
