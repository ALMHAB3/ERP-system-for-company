<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organisation chart: a tree of departments, each with an optional manager.
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120)->unique();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('manager_id')->nullable();       // employees.id
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->foreign('parent_id')->references('id')->on('departments');
        });
        Schema::create('job_positions', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);
            $t->foreignId('department_id')->nullable()->constrained('departments');
            $t->unsignedSmallInteger('headcount')->default(1);       // planned number of people in this position
            $t->timestamps();
            $t->unique(['title', 'department_id']);
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('job_position_id')->nullable();
            $t->unsignedBigInteger('manager_id')->nullable();         // direct manager (employees.id)
        });

        // Employment contracts. Payroll pays what the contract in force on each day says; a raise is a new contract.
        Schema::create('employment_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('reference', 40)->unique();
            $t->string('type', 12);                                   // permanent | fixed_term | probation | part_time
            $t->date('start_date');
            $t->date('end_date')->nullable();                         // null = open-ended
            $t->bigInteger('base_salary');                            // monthly, base currency, 4-decimal units
            $t->bigInteger('monthly_allowance')->default(0);
            $t->string('status', 10)->default('draft');               // draft | active | ended | cancelled
            $t->string('notes', 250)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['department_id', 'job_position_id', 'manager_id']));
        Schema::dropIfExists('job_positions');
        Schema::dropIfExists('departments');
    }
};
