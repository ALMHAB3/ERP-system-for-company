<?php

declare(strict_types=1);

namespace Modules\Hr\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Finance\Application\CashAccounts;
use Modules\Hr\Application\ContractService;
use Modules\Hr\Application\PayrollService;
use Modules\Hr\Domain\ContractRepository;
use Modules\Hr\Http\Concerns\BranchStaff;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

/** Employees, daily attendance, leave requests and advances. */
final class EmployeeController extends ApiController
{
    use BranchStaff;

    public function index(Request $request, ContractRepository $contracts): JsonResponse
    {
        $rows = $this->onlyBranchStaff(DB::table('employees as e'), $request)->leftJoin('warehouses as w', 'w.id', '=', 'e.warehouse_id')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')->leftJoin('job_positions as p', 'p.id', '=', 'e.job_position_id')->leftJoin('employees as m', 'm.id', '=', 'e.manager_id')
            ->orderBy('e.status')->orderBy('e.name')
            ->get(['e.*', 'w.name as branch', 'u.email as login', 'd.name as department', 'p.title as job_title', 'm.name as manager']);
        // Pay is read from the contract in force, never stored on the card.
        $current = $contracts->current($rows->pluck('id')->map(fn ($id) => (int) $id)->all(), new DateTimeImmutable('today'));
        $advances = DB::table('employee_advances')->groupBy('employee_id')->selectRaw('employee_id, SUM(balance) as balance')->pluck('balance', 'employee_id');

        return response()->json(['data' => $rows->map(function ($e) use ($current, $advances) {
            $c = $current[(int) $e->id] ?? null;

            return [
                'id' => (int) $e->id, 'code' => $e->code, 'name' => $e->name, 'national_id' => $e->national_id, 'phone' => $e->phone,
                'department_id' => $e->department_id ? (int) $e->department_id : null, 'department' => $e->department,
                'job_position_id' => $e->job_position_id ? (int) $e->job_position_id : null, 'job_title' => $e->job_title,
                'manager_id' => $e->manager_id ? (int) $e->manager_id : null, 'manager' => $e->manager,
                'warehouse_id' => $e->warehouse_id ? (int) $e->warehouse_id : null, 'branch' => $e->branch, 'user_id' => $e->user_id ? (int) $e->user_id : null, 'login' => $e->login,
                'hire_date' => $e->hire_date, 'status' => $e->status, 'terminated_on' => $e->terminated_on, 'notes' => $e->notes,
                'contract' => $c?->reference, 'base_salary' => $c === null ? null : (string) $c->baseSalary, 'monthly_allowance' => $c === null ? null : (string) $c->monthlyAllowance,
                'advance_balance' => (string) Fixed::units((int) ($advances[$e->id] ?? 0)),
            ];
        })->values()]);
    }

    /** A new employee. When a salary is given, their first contract (permanent, from the hire date) is created and activated with it. */
    public function store(Request $request, AuditLogger $audit, ContractService $contracts): JsonResponse
    {
        $d = $this->rules($request, true);
        if ($this->branchScope($request) !== null) {
            // branch HR adds people to their own branch only
            abort_if(empty($d['warehouse_id']), 422, 'Choose the branch the employee works in.');
            $this->warehouse($request, (int) $d['warehouse_id']);
        }
        $id = DB::transaction(function () use ($d, $audit, $contracts, $request) {
            $id = DB::table('employees')->insertGetId($this->row($d) + ['code' => $d['code'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $audit->record('employee.created', 'employee', $id, ['code' => $d['code'], 'name' => $d['name']]);
            if (isset($d['base_salary'])) {
                $contracts->hire($id, $d['code'], new DateTimeImmutable($d['hire_date']), Decimal::of($d['base_salary']), Decimal::of($d['monthly_allowance'] ?? '0'), $this->userId($request));
            }

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    /** Card details only. Pay changes through a new contract; the place in the chart through the assignment endpoint. */
    public function update(Request $request, int $employee, AuditLogger $audit): JsonResponse
    {
        abort_unless(DB::table('employees')->where('id', $employee)->exists(), 404);
        $this->authorizeStaff($request, $employee);
        $d = $this->rules($request, false, $employee);
        if ($this->branchScope($request) !== null && array_key_exists('warehouse_id', $d)) {
            abort_if(empty($d['warehouse_id']), 422, 'Choose the branch the employee works in.');
            $this->warehouse($request, (int) $d['warehouse_id']);
        }
        DB::transaction(function () use ($d, $employee, $audit) {
            DB::table('employees')->where('id', $employee)->update($this->row($d) + ['updated_at' => now()]);
            $audit->record('employee.updated', 'employee', $employee, $d);
        });

        return response()->json(null, 204);
    }

    /** Ends the service: the employee's running contracts end on the same day. */
    public function terminate(Request $request, int $employee, AuditLogger $audit, ContractService $contracts): JsonResponse
    {
        $d = $request->validate(['terminated_on' => ['required', 'date_format:Y-m-d']]);
        $this->authorizeStaff($request, $employee);
        DB::transaction(function () use ($d, $employee, $audit, $contracts, $request) {
            abort_unless(DB::table('employees')->where('id', $employee)->where('status', 'active')->update(['status' => 'terminated', 'terminated_on' => $d['terminated_on'], 'updated_at' => now()]) === 1, 404);
            $contracts->endEmployment($employee, new DateTimeImmutable($d['terminated_on']), $this->userId($request));
            $audit->record('employee.terminated', 'employee', $employee, $d);
        });

        return response()->json(null, 204);
    }

    public function attendance(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $rows = $this->onlyBranchStaff(DB::table('employees as e'), $request)->leftJoin('attendance as a', fn ($j) => $j->on('a.employee_id', '=', 'e.id')->where('a.work_date', $date))
            ->where('e.status', 'active')->where('e.hire_date', '<=', $date)->orderBy('e.name')->get(['e.id as employee_id', 'e.code', 'e.name', 'a.status', 'a.check_in', 'a.check_out', 'a.note']);

        return response()->json(['date' => $date, 'data' => $rows]);
    }

    public function saveAttendance(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'records.*.status' => ['required', 'in:present,absent,late,leave,holiday'],
            'records.*.check_in' => ['nullable', 'date_format:H:i'], 'records.*.check_out' => ['nullable', 'date_format:H:i'], 'records.*.note' => ['nullable', 'string', 'max:200'],
        ]);
        $this->authorizeStaff($request, ...array_map(fn ($r) => (int) $r['employee_id'], $d['records']));
        DB::transaction(function () use ($d, $audit) {
            foreach ($d['records'] as $r) {
                DB::table('attendance')->updateOrInsert(['employee_id' => $r['employee_id'], 'work_date' => $d['date']],
                    ['status' => $r['status'], 'check_in' => $r['check_in'] ?? null, 'check_out' => $r['check_out'] ?? null, 'note' => $r['note'] ?? null]);
            }
            $audit->record('attendance.saved', 'attendance', 0, ['date' => $d['date'], 'count' => count($d['records'])]);
        });

        return response()->json(['saved' => count($d['records'])]);
    }

    public function leaves(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->onlyBranchStaff(DB::table('leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id'), $request)->orderByDesc('l.id')->limit(300)
            ->get(['l.id', 'e.name as employee', 'l.type', 'l.from_date', 'l.to_date', 'l.days', 'l.reason', 'l.status', 'l.decided_at'])]);
    }

    public function storeLeave(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'type' => ['required', 'in:annual,sick,unpaid,other'],
            'from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'], 'reason' => ['nullable', 'string', 'max:250']]);
        $this->authorizeStaff($request, (int) $d['employee_id']);
        $days = (int) (new DateTimeImmutable($d['from_date']))->diff(new DateTimeImmutable($d['to_date']))->days + 1;
        $id = DB::table('leave_requests')->insertGetId($d + ['days' => $days, 'status' => 'pending', 'created_at' => now()]);
        $audit->record('leave.requested', 'employee', $d['employee_id'], ['leave_id' => $id, 'days' => $days]);

        return response()->json(['id' => $id, 'days' => $days], 201);
    }

    public function decideLeave(Request $request, int $leave, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        $this->authorizeStaff($request, (int) (DB::table('leave_requests')->where('id', $leave)->value('employee_id') ?? 0));
        $n = DB::table('leave_requests')->where('id', $leave)->where('status', 'pending')->update(['status' => $d['decision'], 'decided_by' => $this->userId($request), 'decided_at' => now()]);
        abort_unless($n === 1, 404);
        $audit->record('leave.'.$d['decision'], 'leave_request', $leave, $d);

        return response()->json(null, 204);
    }

    public function advances(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->onlyBranchStaff(DB::table('employee_advances as a')->join('employees as e', 'e.id', '=', 'a.employee_id'), $request)->orderByDesc('a.id')->limit(300)
            ->get(['a.id', 'e.name as employee', 'a.given_on', 'a.amount', 'a.balance', 'a.repay_per_month', 'a.note'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'employee' => $a->employee, 'given_on' => $a->given_on, 'amount' => (string) Fixed::units((int) $a->amount), 'balance' => (string) Fixed::units((int) $a->balance),
                'repay_per_month' => (string) Fixed::units((int) $a->repay_per_month), 'note' => $a->note])]);
    }

    public function giveAdvance(Request $request, PayrollService $payroll, CashAccounts $cash): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'amount' => ['required', Decimal::RULE], 'repay_per_month' => ['required', Decimal::RULE],
            'method' => ['required', 'in:cash,transfer'], 'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'], 'given_on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200']]);
        $account = $cash->resolve($d['method'], isset($d['bank_account_id']) ? (int) $d['bank_account_id'] : null);
        $id = $payroll->giveAdvance($d['employee_id'], Decimal::of($d['amount']), Decimal::of($d['repay_per_month']), $account, $d['given_on'], $d['note'] ?? null);

        return response()->json(['id' => $id], 201);
    }

    /** @return array<string,mixed> */
    private function rules(Request $request, bool $creating, ?int $ignore = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => $creating ? ['required', 'string', 'max:30', 'unique:employees,code'] : ['prohibited'],
            'name' => [$req, 'string', 'max:200'], 'national_id' => ['nullable', 'string', 'max:40'], 'phone' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:250'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => $creating ? ['nullable', 'integer', 'exists:departments,id'] : ['prohibited'],
            'job_position_id' => $creating ? ['nullable', 'integer', 'exists:job_positions,id'] : ['prohibited'],
            'hire_date' => [$req, 'date_format:Y-m-d'],
            // Salary only opens the first contract; afterwards it changes through a new contract.
            'base_salary' => $creating ? ['nullable', Decimal::RULE] : ['prohibited'],
            'monthly_allowance' => $creating ? ['nullable', Decimal::RULE] : ['prohibited'],
        ]);
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function row(array $d): array
    {
        return array_diff_key($d, ['code' => 1, 'base_salary' => 1, 'monthly_allowance' => 1]);
    }
}
