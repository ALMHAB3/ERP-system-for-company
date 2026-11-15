<?php

declare(strict_types=1);

namespace Modules\Hr\Application;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Hr\Domain\ContractRepository;
use Modules\Hr\Domain\Events\AdvanceGiven;
use Modules\Hr\Domain\Events\PayrollApproved;
use Modules\Hr\Domain\Events\PayrollPaid;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Monthly payroll: draft (calculated, editable by recalculating) -> approved (books the expense, recovers advances) -> paid.
 * Pay comes from the employment contracts: each contract in force pays its salary and fixed allowance for its days in the month.
 * net = base + allowances + bonuses - absence - other deductions - advance repayment.
 * Absence = (absent days + approved unpaid-leave days) x the latest contract's salary / 30.
 */
final class PayrollService
{
    public function __construct(private readonly TransactionRunner $tx, private readonly EventBus $events, private readonly AuditLogger $audit, private readonly ContractRepository $contracts) {}

    public function create(string $period, int $userId): int
    {
        $this->assertPeriod($period);

        return $this->tx->run(function () use ($period, $userId): int {
            if (DB::table('payroll_runs')->where('period', $period)->exists()) {
                throw new DomainException("A payroll run for {$period} already exists.");
            }
            $id = DB::table('payroll_runs')->insertGetId(['period' => $period, 'status' => 'draft', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $this->calculate($id);
            $this->audit->record('payroll.created', 'payroll_run', $id, ['period' => $period]);

            return $id;
        });
    }

    /** Rebuilds the lines of a draft run from the current employees, attendance, leaves, adjustments and advances. */
    public function calculate(int $runId): void
    {
        $this->tx->run(function () use ($runId): void {
            $run = DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first() ?? throw new DomainException('Payroll run not found.');
            if ($run->status !== 'draft') {
                throw new DomainException('Only a draft payroll can be recalculated.');
            }
            DB::table('payroll_lines')->where('payroll_run_id', $runId)->delete();
            $start = CarbonImmutable::createFromFormat('Y-m', $run->period)->startOfMonth();
            $end = $start->endOfMonth()->startOfDay();
            $total = 0;
            $employees = DB::table('employees')->where('hire_date', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $start->toDateString()))->orderBy('id')->get();
            foreach ($employees as $e) {
                $from = max($start, CarbonImmutable::parse($e->hire_date));
                $to = $e->terminated_on ? min($end, CarbonImmutable::parse($e->terminated_on)) : $end;
                // The contracts decide the pay: each one pays for the days of the month it was in force.
                // An employee with no contract in force this month is not on the payroll.
                $contracts = $from <= $to ? $this->contracts->inForce((int) $e->id, $from, $to) : [];
                if ($contracts === []) {
                    continue;
                }
                $base = $fixedAllowance = $full = 0;
                foreach ($contracts as $contract) {
                    $days = $contract->daysWithin($from, $to);
                    $whole = $days === $start->daysInMonth;
                    $base += $whole ? $contract->baseSalary->units : Fixed::mulDiv($contract->baseSalary->units, $days, $start->daysInMonth);
                    $fixedAllowance += $whole ? $contract->monthlyAllowance->units : Fixed::mulDiv($contract->monthlyAllowance->units, $days, $start->daysInMonth);
                    $full = $contract->baseSalary->units;   // absence is valued at the latest contract's rate
                }
                $adj = DB::table('payroll_adjustments')->where('employee_id', $e->id)->where('period', $run->period)->get()->groupBy('kind')->map(fn ($g) => (int) $g->sum('amount'));
                $allow = ($adj['allowance'] ?? 0) + $fixedAllowance;   // the employee's fixed monthly allowance + any one-off allowance for this month
                $bonus = $adj['bonus'] ?? 0;
                $other = $adj['deduction'] ?? 0;
                $absent = (int) DB::table('attendance')->where('employee_id', $e->id)->where('status', 'absent')->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->count();
                $unpaid = $this->unpaidLeaveDays((int) $e->id, $start, $end);
                $absence = min(Fixed::mulDiv($full, $absent + $unpaid, 30), max(0, $base + $allow + $bonus - $other));
                $earned = $base + $allow + $bonus - $absence - $other;
                $installment = min((int) DB::table('employee_advances')->where('employee_id', $e->id)->where('balance', '>', 0)->selectRaw('sum(case when balance < repay_per_month then balance else repay_per_month end) as s')->value('s'), max(0, $earned));
                $net = $earned - $installment;
                DB::table('payroll_lines')->insert([
                    'payroll_run_id' => $runId, 'employee_id' => $e->id, 'base' => $base, 'allowances' => $allow, 'bonuses' => $bonus, 'absence_deduction' => $absence,
                    'other_deductions' => $other, 'advance_repayment' => $installment, 'net' => $net, 'absent_days' => $absent + $unpaid,
                ]);
                $total += $net;
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['total_net' => $total, 'updated_at' => now()]);
        });
    }

    public function approve(int $runId, int $userId): void
    {
        $this->tx->run(function () use ($runId, $userId): void {
            $run = DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first() ?? throw new DomainException('Payroll run not found.');
            if ($run->status !== 'draft') {
                throw new DomainException('Only a draft payroll can be approved.');
            }
            $lines = DB::table('payroll_lines')->where('payroll_run_id', $runId)->get();
            if ($lines->isEmpty()) {
                throw new DomainException('This payroll has no employees.');
            }
            foreach ($lines->where('advance_repayment', '>', 0) as $l) {
                $this->recoverAdvances((int) $l->employee_id, (int) $l->advance_repayment);
            }
            $sum = fn (string $k) => (int) $lines->sum($k);
            $expense = $sum('base') + $sum('allowances') + $sum('bonuses') - $sum('absence_deduction') - $sum('other_deductions');
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'approved', 'approved_by' => $userId, 'total_net' => $sum('net'), 'updated_at' => now()]);
            $this->events->dispatch(new PayrollApproved($runId, $run->period, Fixed::units($expense), Fixed::units($sum('net')), Fixed::units($sum('advance_repayment')), $this->byBranch($runId)));
            $this->audit->record('payroll.approved', 'payroll_run', $runId, ['period' => $run->period, 'net' => (string) Fixed::units($sum('net'))]);
        });
    }

    public function pay(int $runId, string $cashAccount, string $paidOn): void
    {
        $this->tx->run(function () use ($runId, $cashAccount, $paidOn): void {
            $run = DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first() ?? throw new DomainException('Payroll run not found.');
            if ($run->status !== 'approved') {
                throw new DomainException('Only an approved payroll can be paid.');
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'paid', 'paid_on' => $paidOn, 'updated_at' => now()]);
            $this->events->dispatch(new PayrollPaid($runId, $run->period, Fixed::units((int) $run->total_net), $cashAccount, array_map(static fn (array $b) => $b['net'], $this->byBranch($runId))));
            $this->audit->record('payroll.paid', 'payroll_run', $runId, ['period' => $run->period, 'account' => $cashAccount]);
        });
    }

    /**
     * Salary cost, net pay and advance repayments per branch of the employees (0 = head office), so each branch carries its own payroll.
     *
     * @return array<int,array{expense:Fixed,net:Fixed,advances:Fixed}>
     */
    private function byBranch(int $runId): array
    {
        $out = [];
        $rows = DB::table('payroll_lines as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->where('l.payroll_run_id', $runId)
            ->groupBy('e.warehouse_id')
            ->selectRaw('e.warehouse_id as branch, SUM(l.base + l.allowances + l.bonuses - l.absence_deduction - l.other_deductions) as expense, SUM(l.net) as net, SUM(l.advance_repayment) as advances')
            ->get();
        foreach ($rows as $r) {
            $out[(int) ($r->branch ?? 0)] = ['expense' => Fixed::units((int) $r->expense), 'net' => Fixed::units((int) $r->net), 'advances' => Fixed::units((int) $r->advances)];
        }
        ksort($out);

        return $out;
    }

    public function giveAdvance(int $employeeId, Fixed $amount, Fixed $perMonth, string $cashAccount, string $givenOn, ?string $note): int
    {
        if (! $amount->isPositive() || ! $perMonth->isPositive()) {
            throw new DomainException('The advance and the monthly repayment must be positive.');
        }

        return $this->tx->run(function () use ($employeeId, $amount, $perMonth, $cashAccount, $givenOn, $note): int {
            $e = DB::table('employees')->where('id', $employeeId)->where('status', 'active')->first() ?? throw new DomainException('Employee not found or not active.');
            $id = DB::table('employee_advances')->insertGetId(['employee_id' => $employeeId, 'given_on' => $givenOn, 'amount' => $amount->units, 'balance' => $amount->units,
                'repay_per_month' => $perMonth->units, 'cash_account' => $cashAccount, 'note' => $note, 'created_at' => now()]);
            $this->events->dispatch(new AdvanceGiven($id, $e->name, $amount, $cashAccount));
            $this->audit->record('advance.given', 'employee', $employeeId, ['amount' => (string) $amount]);

            return $id;
        });
    }

    /** Approved unpaid-leave days that fall inside [start, end]. */
    private function unpaidLeaveDays(int $employeeId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $days = 0;
        foreach (DB::table('leave_requests')->where('employee_id', $employeeId)->where('status', 'approved')->where('type', 'unpaid')->where('from_date', '<=', $end->toDateString())->where('to_date', '>=', $start->toDateString())->get() as $l) {
            $days += (int) max($start, CarbonImmutable::parse($l->from_date))->diffInDays(min($end, CarbonImmutable::parse($l->to_date))) + 1;
        }

        return $days;
    }

    private function recoverAdvances(int $employeeId, int $amount): void
    {
        foreach (DB::table('employee_advances')->where('employee_id', $employeeId)->where('balance', '>', 0)->orderBy('id')->lockForUpdate()->get() as $a) {
            if ($amount <= 0) {
                break;
            }
            $take = min($amount, (int) $a->balance, (int) $a->repay_per_month);
            DB::table('employee_advances')->where('id', $a->id)->update(['balance' => (int) $a->balance - $take]);
            $amount -= $take;
        }
    }

    private function assertPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new DomainException('Period must look like 2026-03.');
        }
    }
}
