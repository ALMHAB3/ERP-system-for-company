<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;

/** Organisation chart, employment contracts, and payroll paying what the contracts say. */
final class OrgAndContractsApiTest extends ApiTestCase
{
    private object $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->userWith(['hr_manager']);
    }

    private function employee(string $code, string $salary = '3000'): int
    {
        return $this->as($this->hr)->postJson('/api/hr/employees', ['code' => $code, 'name' => 'Emp '.$code, 'hire_date' => '2020-01-01', 'base_salary' => $salary])->assertCreated()->json('id');
    }

    public function test_departments_form_a_tree_without_loops(): void
    {
        $boss = $this->employee('B1');
        $clerk = $this->employee('C1');
        $company = $this->as($this->hr)->postJson('/api/hr/departments', ['name' => 'Company', 'manager_id' => $boss])->assertCreated()->json('id');
        $sales = $this->as($this->hr)->postJson('/api/hr/departments', ['name' => 'Sales', 'parent_id' => $company])->assertCreated()->json('id');
        $retail = $this->as($this->hr)->postJson('/api/hr/departments', ['name' => 'Retail', 'parent_id' => $sales])->assertCreated()->json('id');
        $this->as($this->hr)->postJson('/api/hr/departments', ['name' => 'Sales'])->assertUnprocessable();                                    // unique name
        $this->as($this->hr)->putJson("/api/hr/departments/{$company}", ['parent_id' => $retail])->assertUnprocessable();                      // would loop
        $this->as($this->hr)->putJson("/api/hr/departments/{$sales}", ['parent_id' => $sales])->assertUnprocessable();
        $this->as($this->userWith(['accountant']))->getJson('/api/hr/org-chart')->assertForbidden();

        $position = $this->as($this->hr)->postJson('/api/hr/positions', ['title' => 'Cashier', 'department_id' => $retail, 'headcount' => 3])->assertCreated()->json('id');
        $this->as($this->hr)->putJson("/api/hr/employees/{$boss}/assignment", ['department_id' => $company])->assertNoContent();
        $this->as($this->hr)->putJson("/api/hr/employees/{$clerk}/assignment", ['department_id' => $retail, 'job_position_id' => $position, 'manager_id' => $boss])->assertNoContent();
        $this->as($this->hr)->putJson("/api/hr/employees/{$boss}/assignment", ['department_id' => $company, 'manager_id' => $clerk])->assertUnprocessable();   // reporting loop
        $this->as($this->hr)->putJson("/api/hr/employees/{$boss}/assignment", ['department_id' => $company, 'manager_id' => $boss])->assertUnprocessable();

        $chart = $this->as($this->hr)->getJson('/api/hr/org-chart')->assertOk()->json();
        $root = $chart['departments'][0];
        $this->assertSame('Company', $root['name']);
        $this->assertSame('Emp B1', $root['manager']);
        $this->assertSame(2, $root['headcount']);                                          // everyone below it
        $leaf = $root['children'][0]['children'][0];
        $this->assertSame('Retail', $leaf['name']);
        $this->assertSame('Cashier', $leaf['employees'][0]['position']);
        $this->assertSame([], $chart['unassigned']);
        $this->as($this->hr)->getJson('/api/hr/departments')->assertOk()->assertJsonPath('positions.0.filled', 1)->assertJsonPath('positions.0.headcount', 3);
    }

    public function test_contracts_never_overlap_and_a_new_one_takes_over(): void
    {
        $e = $this->employee('E1', '1000');
        $body = ['employee_id' => $e, 'reference' => 'CT-1', 'type' => 'permanent', 'start_date' => '2020-01-01', 'base_salary' => '3000', 'monthly_allowance' => '200'];
        $this->as($this->hr)->postJson('/api/hr/contracts', ['type' => 'fixed_term', 'reference' => 'CT-0'] + $body)->assertUnprocessable();   // fixed term needs an end
        $this->as($this->hr)->postJson('/api/hr/contracts', ['start_date' => '2019-01-01', 'reference' => 'CT-0'] + $body)->assertUnprocessable();   // before hire
        $first = $this->as($this->hr)->postJson('/api/hr/contracts', $body)->assertCreated()->json('id');
        $this->assertSame(10000000, (int) DB::table('employees')->where('id', $e)->value('base_salary'));   // a draft changes nothing
        $this->as($this->hr)->postJson("/api/hr/contracts/{$first}/activate")->assertNoContent();
        $this->as($this->hr)->postJson("/api/hr/contracts/{$first}/activate")->assertUnprocessable();
        $this->assertSame(30000000, (int) DB::table('employees')->where('id', $e)->value('base_salary'));   // the employee card follows the contract
        $this->as($this->finance())->getJson('/api/hr/employees')->assertJsonPath('data.0.monthly_allowance', '200.0000');

        // Same start date: a real overlap, refused.
        $dup = $this->as($this->hr)->postJson('/api/hr/contracts', ['reference' => 'CT-DUP'] + $body)->assertCreated()->json('id');
        $this->as($this->hr)->postJson("/api/hr/contracts/{$dup}/activate")->assertUnprocessable();
        $this->as($this->hr)->postJson("/api/hr/contracts/{$dup}/cancel")->assertNoContent();

        // A raise from 2024: the old contract ends the day before.
        $this->as($this->hr)->postJson('/api/hr/contracts', ['reference' => 'CT-2', 'start_date' => '2024-01-01', 'base_salary' => '4000', 'activate' => true] + $body)->assertCreated();
        $rows = collect($this->as($this->hr)->getJson('/api/hr/contracts?employee_id='.$e)->assertOk()->json('data'))->keyBy('reference');
        $this->assertSame(['ended', '2023-12-31'], [$rows['CT-1']['status'], $rows['CT-1']['end_date']]);
        $this->assertSame('active', $rows['CT-2']['status']);
        $this->assertSame('cancelled', $rows['CT-DUP']['status']);
        $this->assertSame(40000000, (int) DB::table('employees')->where('id', $e)->value('base_salary'));

        // A contract ending soon shows up in the expiry list.
        $temp = $this->employee('T1');
        $soon = CarbonImmutable::today()->addDays(20)->format('Y-m-d');
        $this->as($this->hr)->postJson('/api/hr/contracts', ['employee_id' => $temp, 'reference' => 'CT-T', 'type' => 'fixed_term', 'start_date' => '2021-01-01', 'end_date' => $soon, 'base_salary' => '1500', 'activate' => true])->assertCreated();
        $this->as($this->hr)->getJson('/api/hr/contracts?expiring_within=30')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'CT-T');
        $this->as($this->userWith(['sales_rep'], [$this->warehouse]))->getJson('/api/hr/contracts')->assertForbidden();
    }

    public function test_payroll_pays_each_contract_for_the_days_it_was_in_force(): void
    {
        $month = CarbonImmutable::today()->subMonth()->startOfMonth();
        $days = $month->daysInMonth;
        $raiseOn = $month->addDays(15)->format('Y-m-d');                                   // the 16th
        $e = $this->employee('E1', '9999');                                               // the card value is ignored once contracts exist
        $this->employee('E2', '2000');                                                    // no contract: paid from the card as before
        $this->as($this->hr)->postJson('/api/hr/contracts', ['employee_id' => $e, 'reference' => 'CT-1', 'type' => 'permanent', 'start_date' => '2020-01-01', 'base_salary' => '3000', 'monthly_allowance' => '300', 'activate' => true])->assertCreated();
        $this->as($this->hr)->postJson('/api/hr/contracts', ['employee_id' => $e, 'reference' => 'CT-2', 'type' => 'permanent', 'start_date' => $raiseOn, 'base_salary' => '6000', 'activate' => true])->assertCreated();

        $run = $this->as($this->hr)->postJson('/api/hr/payroll', ['period' => $month->format('Y-m')])->assertCreated()->json('id');
        $lines = collect($this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines'))->keyBy('code');
        $expectedBase = Fixed::mulDiv(30000000, 15, $days) + Fixed::mulDiv(60000000, $days - 15, $days);
        $this->assertSame((string) Fixed::units($expectedBase), $lines['E1']['base']);
        $this->assertSame((string) Fixed::units(Fixed::mulDiv(3000000, 15, $days)), $lines['E1']['allowances']);   // the allowance stopped with the first contract
        $this->assertSame('2000.0000', $lines['E2']['base']);

        // Ending the contract mid-month stops the pay on that day.
        $third = $this->employee('E3', '1');
        $c = $this->as($this->hr)->postJson('/api/hr/contracts', ['employee_id' => $third, 'reference' => 'CT-3', 'type' => 'permanent', 'start_date' => '2020-01-01', 'base_salary' => '3000', 'activate' => true])->assertCreated()->json('id');
        $this->as($this->hr)->postJson("/api/hr/contracts/{$c}/end", ['end_date' => $month->addDays(9)->format('Y-m-d')])->assertNoContent();
        $this->as($this->hr)->postJson("/api/hr/payroll/{$run}/recalculate")->assertNoContent();
        $lines = collect($this->as($this->hr)->getJson("/api/hr/payroll/{$run}")->json('lines'))->keyBy('code');
        $this->assertSame((string) Fixed::units(Fixed::mulDiv(30000000, 10, $days)), $lines['E3']['base']);
    }

    private function finance(): object
    {
        return $this->userWith(['finance_manager']);
    }
}
