<?php

declare(strict_types=1);

namespace Modules\Hr\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Finance\Application\CashAccounts;
use Modules\Hr\Application\PayrollService;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class PayrollController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('payroll_runs')->orderByDesc('period')->limit(60)->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'period' => $r->period, 'status' => $r->status, 'total_net' => (string) Fixed::units((int) $r->total_net), 'paid_on' => $r->paid_on,
            'employees' => DB::table('payroll_lines')->where('payroll_run_id', $r->id)->count(),
        ])]);
    }

    public function show(int $run): JsonResponse
    {
        $r = DB::table('payroll_runs')->where('id', $run)->first() ?? abort(404);
        $f = fn ($v) => (string) Fixed::units((int) $v);
        $lines = DB::table('payroll_lines as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->where('l.payroll_run_id', $run)->orderBy('e.name')
            ->get(['l.*', 'e.name', 'e.code', 'e.job_title'])->map(fn ($l) => [
                'employee_id' => (int) $l->employee_id, 'code' => $l->code, 'name' => $l->name, 'job_title' => $l->job_title, 'base' => $f($l->base), 'allowances' => $f($l->allowances), 'bonuses' => $f($l->bonuses),
                'absence_deduction' => $f($l->absence_deduction), 'absent_days' => (string) $l->absent_days, 'other_deductions' => $f($l->other_deductions), 'advance_repayment' => $f($l->advance_repayment), 'net' => $f($l->net),
            ]);

        return response()->json(['id' => (int) $r->id, 'period' => $r->period, 'status' => $r->status, 'total_net' => $f($r->total_net), 'paid_on' => $r->paid_on, 'lines' => $lines]);
    }

    public function store(Request $request, PayrollService $service): JsonResponse
    {
        $d = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return response()->json(['id' => $service->create($d['period'], $this->userId($request))], 201);
    }

    public function recalculate(int $run, PayrollService $service): JsonResponse
    {
        $service->calculate($run);

        return response()->json(null, 204);
    }

    public function approve(Request $request, int $run, PayrollService $service): JsonResponse
    {
        $service->approve($run, $this->userId($request));

        return response()->json(null, 204);
    }

    public function pay(Request $request, int $run, PayrollService $service, CashAccounts $cash): JsonResponse
    {
        $d = $request->validate(['method' => ['required', 'in:cash,transfer'], 'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'], 'paid_on' => ['required', 'date_format:Y-m-d']]);
        $service->pay($run, $cash->resolve($d['method'], isset($d['bank_account_id']) ? (int) $d['bank_account_id'] : null), $d['paid_on']);

        return response()->json(null, 204);
    }

    /** Allowances, bonuses and one-off deductions for a month (applied when the draft is recalculated). */
    public function adjust(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'kind' => ['required', 'in:allowance,bonus,deduction'], 'amount' => ['required', Decimal::RULE], 'note' => ['nullable', 'string', 'max:200']]);
        abort_if(DB::table('payroll_runs')->where('period', $d['period'])->where('status', '!=', 'draft')->exists(), 422, 'This month is already approved.');
        $id = DB::table('payroll_adjustments')->insertGetId(['employee_id' => $d['employee_id'], 'period' => $d['period'], 'kind' => $d['kind'], 'amount' => Decimal::of($d['amount'])->units, 'note' => $d['note'] ?? null, 'created_at' => now()]);
        $audit->record('payroll.adjusted', 'employee', $d['employee_id'], ['period' => $d['period'], 'kind' => $d['kind'], 'amount' => $d['amount']]);

        return response()->json(['id' => $id], 201);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $p = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'];

        return response()->json(['data' => DB::table('payroll_adjustments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.period', $p)->orderBy('a.id')
            ->get(['a.id', 'e.name as employee', 'a.kind', 'a.amount', 'a.note'])->map(fn ($a) => ['id' => (int) $a->id, 'employee' => $a->employee, 'kind' => $a->kind, 'amount' => (string) Fixed::units((int) $a->amount), 'note' => $a->note])]);
    }
}
