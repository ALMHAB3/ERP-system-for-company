<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts and the organisation chart replace four free fields of the employee card:
 * base_salary + monthly_allowance  -> one employment contract per employee (active, or ended if they left);
 * department + job_title (text)    -> a department and a job position of the chart.
 * Existing values are carried over before the columns are dropped, so no history is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (DB::table('employees')->orderBy('id')->get() as $e) {
            $departmentId = null;
            $name = trim((string) $e->department);
            if ($name !== '') {
                $departmentId = DB::table('departments')->where('name', $name)->value('id')
                    ?? DB::table('departments')->insertGetId(['name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $positionId = null;
            $title = trim((string) $e->job_title);
            if ($title !== '') {
                $positionId = DB::table('job_positions')->where('title', $title)->where('department_id', $departmentId)->value('id')
                    ?? DB::table('job_positions')->insertGetId(['title' => $title, 'department_id' => $departmentId, 'headcount' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('employees')->where('id', $e->id)->update(['department_id' => $e->department_id ?? $departmentId, 'job_position_id' => $e->job_position_id ?? $positionId]);

            $hasContract = DB::table('employment_contracts')->where('employee_id', $e->id)->whereIn('status', ['active', 'ended'])->exists();
            if (! $hasContract && (int) $e->base_salary > 0) {
                DB::table('employment_contracts')->insert([
                    'employee_id' => $e->id, 'reference' => $e->code.'-1', 'type' => 'permanent',
                    'start_date' => $e->hire_date, 'end_date' => $e->terminated_on,
                    'base_salary' => (int) $e->base_salary, 'monthly_allowance' => (int) $e->monthly_allowance,
                    'status' => $e->terminated_on ? 'ended' : 'active', 'notes' => 'Carried over from the employee card',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        // Headcount of a carried-over position = the people who hold it.
        foreach (DB::table('job_positions')->get(['id']) as $p) {
            $held = DB::table('employees')->where('job_position_id', $p->id)->count();
            if ($held > 1) {
                DB::table('job_positions')->where('id', $p->id)->update(['headcount' => $held]);
            }
        }
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['job_title', 'department', 'base_salary', 'monthly_allowance']));
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('job_title', 120)->nullable();
            $t->string('department', 120)->nullable();
            $t->bigInteger('base_salary')->default(0);
            $t->bigInteger('monthly_allowance')->default(0);
        });
        foreach (DB::table('employees')->get(['id', 'department_id', 'job_position_id']) as $e) {
            $c = DB::table('employment_contracts')->where('employee_id', $e->id)->whereIn('status', ['active', 'ended'])->orderByDesc('start_date')->first();
            DB::table('employees')->where('id', $e->id)->update([
                'department' => $e->department_id ? DB::table('departments')->where('id', $e->department_id)->value('name') : null,
                'job_title' => $e->job_position_id ? DB::table('job_positions')->where('id', $e->job_position_id)->value('title') : null,
                'base_salary' => $c ? (int) $c->base_salary : 0, 'monthly_allowance' => $c ? (int) $c->monthly_allowance : 0,
            ]);
        }
    }
};
