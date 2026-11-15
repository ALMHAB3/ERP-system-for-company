<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Domain\Fixed;

/** Employees, attendance, leave, advances and a monthly payroll that books balanced entries. */
final class HrPayrollApiTest extends ApiTestCase
{
    private object $hr;

    private object $finance;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->userWith(['hr_manager']);
        $this->finance = $this->userWith(['finance_manager']);
        $this->period = CarbonImmutable::today()->subMonth()->format('Y-m');
    }

    private function balance(string $account): Fixed
    {
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            if ($r['account_code'] === $account) {
                return Fixed::parse($r['debit'])->sub(Fixed::parse($r['credit']));
            }
        }

        return Fixed::zero();
    }

    private function employee(string $code = 'E1', string $salary = '3000', string $hired = '2020-01-01'): int
    {
        return $this->as($this->hr)->postJson('/api/hr/employees', ['code' => $code, 'name' => 'Ali '.$code, 'hire_date' => $hired, 'base_salary' => $salary, 'warehouse_id' => $this->warehouse])->assertCreated()->json('id');
    }

    public function test_only_hr_and_finance_see_salaries(): void
    {
        $this->employee();
        $this->as($this->userWith(['sales_rep'], [$this->warehouse]))->getJson('/api/hr/employees')->assertForbidden();
        $this->as($this->userWith(['accountant']))->getJson('/api/hr/employees')->assertForbidden();
        $this->as($this->finance)->getJson('/api/hr/employees')->assertOk()->assertJsonPath('data.0.base_salary', '3000.0000');
        $this->as($this->hr)->postJson('/api/hr/employees', ['code' => 'E1', 'name' => 'dup', 'hire_date' => '2020-01-01', 'base_salary' => '1'])->assertUnprocessable();   // unique code
    }

    public function test_payroll_calculates_books_balanced_entries_and_is_paid(): void
    {
        $e = $this->employee();
        $day1 = $this->period.'-01';
        $day2 = $this->period.'-02';
        $this->as($this->hr)->putJson('/api/hr/attendance', ['date' => $day1, 'records' => [['employee_id' => $e, 'status' => 'absent']]])->assertOk();
        $this->as($this->hr)->putJson('/api/hr/attendance', ['date' => $day2, 'records' => [['employee_id' => $e, 'status' => 'absent']]])->assertOk();
        $this->as($this->hr)->postJson('/api/hr/advances', ['employee_id' => $e, 'amount' => '600', 'repay_per_month' => '200', 'method' => 'cash', 'given_on' => $day1])->assertCreated();
        $this->assertSame('600.0000', (string) $this->balance('1350'));
        foreach ([['allowance', '100'], ['bonus', '50'], ['deduction', '30']] as [$k, $a]) {
            $this->as($this->hr)->postJson('/api/hr/payroll/adjustments', ['employee_id' => $e, 'period' => $this->period, 'kind' => $k, 'amount' => $a])->assertCreated();
        }

        $run = $this->as($this->hr)->postJson('/api/hr/payroll', ['period' => $this->period])->assertCreated()->json('id');
        $this->as($this->hr)->postJson('/api/hr/payroll', ['period' => $this->period])->assertUnprocessable();   // one run per month
        $l = $this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->assertOk()->json('lines.0');
        $this->assertSame('3000.0000', $l['base']);
        $this->assertSame('200.0000', $l['absence_deduction']);          // 2 days x 3000 / 30
        $this->assertSame('200.0000', $l['advance_repayment']);
        $this->assertSame('2720.0000', $l['net']);   // 2920 earned - 200 advance instalment

        $this->as($this->hr)->postJson("/api/hr/payroll/{$run}/pay", ['method' => 'cash', 'paid_on' => $day2])->assertForbidden();   // HR cannot pay
        $this->as($this->finance)->postJson("/api/hr/payroll/{$run}/pay", ['method' => 'cash', 'paid_on' => $day2])->assertUnprocessable();   // not approved yet
        $this->as($this->hr)->postJson("/api/hr/payroll/{$run}/approve")->assertNoContent();
        $this->as($this->hr)->postJson("/api/hr/payroll/{$run}/recalculate")->assertUnprocessable();   // locked once approved

        $this->assertSame('2920.0000', (string) $this->balance('5500'));
        $this->assertSame('-2720.0000', (string) $this->balance('2300'));
        $this->assertSame('400.0000', (string) $this->balance('1350'));   // 600 given - 200 recovered
        $this->assertSame('400.0000', (string) Fixed::units((int) DB::table('employee_advances')->value('balance')));

        $this->as($this->finance)->postJson("/api/hr/payroll/{$run}/pay", ['method' => 'cash', 'paid_on' => $day2])->assertNoContent();
        $this->assertSame('0.0000', (string) $this->balance('2300'));
        $this->assertSame('-3320.0000', (string) $this->balance('1110'));   // 600 advance + 2720 salary out of the till
        $this->as($this->finance)->postJson("/api/hr/payroll/{$run}/pay", ['method' => 'cash', 'paid_on' => $day2])->assertUnprocessable();   // paid once only
        $debits = array_sum(array_map(fn ($r) => (int) Fixed::parse($r['debit'])->units, app(JournalRepository::class)->trialBalance()));
        $credits = array_sum(array_map(fn ($r) => (int) Fixed::parse($r['credit'])->units, app(JournalRepository::class)->trialBalance()));
        $this->assertSame($debits, $credits);   // the books still balance
    }

    public function test_fixed_monthly_allowance_is_added_every_month_and_is_optional(): void
    {
        $with = $this->as($this->hr)->postJson('/api/hr/employees', ['code' => 'A1', 'name' => 'With', 'hire_date' => '2020-01-01', 'base_salary' => '2000', 'monthly_allowance' => '150'])->assertCreated()->json('id');
        $this->as($this->hr)->postJson('/api/hr/employees', ['code' => 'A2', 'name' => 'Without', 'hire_date' => '2020-01-01', 'base_salary' => '2000'])->assertCreated();
        $run = $this->as($this->hr)->postJson('/api/hr/payroll', ['period' => $this->period])->assertCreated()->json('id');
        $lines = collect($this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines'))->keyBy('code');
        $this->assertSame('150.0000', $lines['A1']['allowances']);
        $this->assertSame('2150.0000', $lines['A1']['net']);
        $this->assertSame('0.0000', $lines['A2']['allowances']);
        $this->as($this->hr)->putJson("/api/hr/employees/{$with}", ['monthly_allowance' => '0'])->assertUnprocessable();   // pay is not on the card any more
        // Removing it = a new contract without the allowance from the first of the month.
        $this->as($this->hr)->postJson('/api/hr/contracts', ['employee_id' => $with, 'reference' => 'A1-2', 'type' => 'permanent', 'start_date' => $this->period.'-01', 'base_salary' => '2000', 'activate' => true])->assertCreated();
        $this->as($this->hr)->postJson("/api/hr/payroll/{$run}/recalculate")->assertNoContent();
        $this->assertSame('0.0000', collect($this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines'))->keyBy('code')['A1']['allowances']);
    }

    public function test_unpaid_leave_and_mid_month_hire_are_handled(): void
    {
        $first = $this->employee('E1', '3000', '2020-01-01');
        $start = CarbonImmutable::createFromFormat('Y-m', $this->period)->startOfMonth();
        $second = $this->employee('E2', '3000', $start->addDays(14)->toDateString());   // hired on the 15th
        $leave = $this->as($this->hr)->postJson('/api/hr/leaves', ['employee_id' => $first, 'type' => 'unpaid', 'from_date' => $this->period.'-10', 'to_date' => $this->period.'-12'])->assertCreated()->assertJsonPath('days', 3)->json('id');
        $this->as($this->hr)->postJson("/api/hr/leaves/{$leave}/decision", ['decision' => 'approved'])->assertNoContent();
        $this->as($this->hr)->postJson("/api/hr/leaves/{$leave}/decision", ['decision' => 'rejected'])->assertNotFound();   // decided once

        $run = $this->as($this->hr)->postJson('/api/hr/payroll', ['period' => $this->period])->assertCreated()->json('id');
        $lines = collect($this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines'))->keyBy('code');
        $this->assertSame('300.0000', $lines['E1']['absence_deduction']);   // 3 unpaid days x 100
        $days = $start->daysInMonth;
        $this->assertSame((string) Fixed::units(Fixed::mulDiv(3000 * Fixed::FACTOR, $days - 14, $days)), $lines['E2']['base']);   // paid from the 15th
    }

    public function test_attendance_cannot_be_recorded_for_the_future(): void
    {
        $e = $this->employee();
        $this->as($this->hr)->putJson('/api/hr/attendance', ['date' => CarbonImmutable::tomorrow()->toDateString(), 'records' => [['employee_id' => $e, 'status' => 'present']]])->assertUnprocessable();
    }

    public function test_pay_comes_only_from_contracts_and_leaving_ends_the_contract(): void
    {
        $paid = $this->employee('P1', '3000');
        $this->as($this->hr)->postJson('/api/hr/employees', ['code' => 'N1', 'name' => 'No contract', 'hire_date' => '2020-01-01'])->assertCreated();
        $this->as($this->hr)->putJson("/api/hr/employees/{$paid}", ['base_salary' => '9000'])->assertUnprocessable();
        $this->as($this->finance)->getJson('/api/hr/employees')->assertOk();
        $cards = collect($this->as($this->finance)->getJson('/api/hr/employees')->json('data'))->keyBy('code');
        $this->assertSame(['P1-1', '3000.0000'], [$cards['P1']['contract'], $cards['P1']['base_salary']]);
        $this->assertNull($cards['N1']['contract']);

        $run = $this->as($this->hr)->postJson('/api/hr/payroll', ['period' => $this->period])->assertCreated()->json('id');
        $this->assertSame(['P1'], collect($this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines'))->pluck('code')->all());   // no contract, not on the payroll

        $this->as($this->hr)->postJson("/api/hr/employees/{$paid}/terminate", ['terminated_on' => $this->period.'-10'])->assertNoContent();
        $contract = collect($this->as($this->hr)->getJson('/api/hr/contracts?employee_id='.$paid)->json('data'))->first();
        $this->assertSame(['ended', $this->period.'-10'], [$contract['status'], $contract['end_date']]);
        $this->as($this->hr)->postJson("/api/hr/payroll/{$run}/recalculate")->assertNoContent();
        $days = CarbonImmutable::createFromFormat('Y-m-d', $this->period.'-01')->daysInMonth;
        $this->assertSame((string) Fixed::units(Fixed::mulDiv(30000000, 10, $days)), $this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines.0.base'));
    }
}
