<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 200);
            $t->string('job_title', 120)->nullable();
            $t->string('department', 120)->nullable();
            $t->string('national_id', 40)->nullable();
            $t->string('phone', 40)->nullable();
            $t->foreignId('warehouse_id')->nullable()->constrained('warehouses');   // the branch they work in
            $t->unsignedBigInteger('user_id')->nullable();                           // their system login, if any
            $t->date('hire_date');
            $t->bigInteger('base_salary')->default(0);                               // monthly, base currency, 4-decimal units
            $t->string('status', 12)->default('active');                             // active | terminated
            $t->date('terminated_on')->nullable();
            $t->string('notes', 250)->nullable();
            $t->timestamps();
        });

        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->date('work_date');
            $t->string('status', 10);                                                // present | absent | late | leave | holiday
            $t->time('check_in')->nullable();
            $t->time('check_out')->nullable();
            $t->string('note', 200)->nullable();
            $t->unique(['employee_id', 'work_date']);
            $t->index('work_date');
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('type', 10);                                                  // annual | sick | unpaid | other
            $t->date('from_date');
            $t->date('to_date');
            $t->unsignedSmallInteger('days');
            $t->string('reason', 250)->nullable();
            $t->string('status', 10)->default('pending');                            // pending | approved | rejected
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('employee_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->date('given_on');
            $t->bigInteger('amount');
            $t->bigInteger('balance');                                               // still to be repaid
            $t->bigInteger('repay_per_month');
            $t->string('cash_account', 16);
            $t->string('note', 200)->nullable();
            $t->dateTime('created_at');
        });

        Schema::create('payroll_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('period', 7);                                                 // YYYY-MM
            $t->string('kind', 10);                                                  // allowance | bonus | deduction
            $t->bigInteger('amount');
            $t->string('note', 200)->nullable();
            $t->dateTime('created_at');
            $t->index(['period', 'employee_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->string('period', 7)->unique();
            $t->string('status', 10)->default('draft');                              // draft | approved | paid
            $t->bigInteger('total_net')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->date('paid_on')->nullable();
            $t->timestamps();
        });

        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained('payroll_runs');
            $t->foreignId('employee_id')->constrained('employees');
            $t->bigInteger('base');
            $t->bigInteger('allowances')->default(0);
            $t->bigInteger('bonuses')->default(0);
            $t->bigInteger('absence_deduction')->default(0);
            $t->bigInteger('other_deductions')->default(0);
            $t->bigInteger('social_employee')->default(0);
            $t->bigInteger('social_employer')->default(0);
            $t->bigInteger('advance_repayment')->default(0);
            $t->bigInteger('net');
            $t->decimal('absent_days', 6, 1)->default(0);
            $t->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['payroll_lines', 'payroll_runs', 'payroll_adjustments', 'employee_advances', 'leave_requests', 'attendance', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
