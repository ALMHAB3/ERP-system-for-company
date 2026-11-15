<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;

/**
 * Two branches. Each has its own manager, sellers, accountant and HR; the company accountant sees everything.
 * Every ledger line carries its branch, so each branch has its own books, till and profit and loss.
 */
final class BranchOperationsApiTest extends ApiTestCase
{
    private int $a;

    private int $b;

    private int $customer;

    private object $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->warehouse;
        $this->b = $this->makeWarehouse('BR2');
        $this->customer = DB::table('customers')->insertGetId(['name' => 'Al Noor', 'category' => 'retail', 'credit_limit' => 100000 * Fixed::FACTOR, 'terms_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('price_list_items')->insert(['product_id' => $this->product, 'category' => 'retail', 'price' => 100 * Fixed::FACTOR]);
        $this->finance = $this->userWith(['finance_manager']);
        $this->as($this->finance)->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '1'])->assertCreated();
        foreach ([$this->a, $this->b] as $i => $w) {   // 100 units at cost 10 in each branch
            $s = $this->as($this->userWith(['purchasing_manager']))->postJson('/api/shipments', ['reference' => "IMP-{$i}", 'currency' => 'USD', 'lines' => [['product_id' => $this->product, 'quantity' => '100', 'unit_price' => '10', 'duty_rate' => '0']]])->assertCreated()->json('id');
            $this->as($this->finance)->postJson("/api/shipments/{$s}/finalize")->assertOk();
            $this->as($this->userWith(['warehouse_clerk'], [$w]))->postJson("/api/shipments/{$s}/receive", ['warehouse_id' => $w])->assertOk();
        }
    }

    /** A seller of branch $w sells $qty units at 100; the branch clerk ships; the invoice is issued. */
    private function sell(int $w, string $qty): int
    {
        $order = $this->as($this->userWith(['sales_rep'], [$w]))->postJson('/api/sales-orders', ['customer_id' => $this->customer, 'warehouse_id' => $w, 'lines' => [['product_id' => $this->product, 'quantity' => $qty]]])->assertCreated()->json('id');
        $this->as($this->userWith(['warehouse_clerk'], [$w]))->postJson("/api/sales-orders/{$order}/ship")->assertOk();
        $this->as($this->finance)->postJson("/api/sales-orders/{$order}/invoice")->assertCreated();

        return (int) DB::table('sales_invoices')->where('sales_order_id', $order)->value('id');
    }

    private function profit(object $user, string $query = ''): array
    {
        $today = CarbonImmutable::today()->format('Y-m-d');

        return collect($this->as($user)->getJson("/api/finance/branch-profit?from=2020-01-01&to={$today}{$query}")->assertOk()->json('data'))->keyBy(fn ($r) => $r['branch_id'] ?? 0)->all();
    }

    public function test_each_branch_has_its_own_books_and_the_company_accountant_sees_all(): void
    {
        $invA = $this->sell($this->a, '2');    // revenue 200, cost 20
        $invB = $this->sell($this->b, '3');    // revenue 300, cost 30

        // every ledger line of a sale carries the branch that sold
        $this->assertSame(0, DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->whereIn('e.source_type', ['sales_invoice'])->whereNull('l.warehouse_id')->count());

        $accA = $this->userWith(['branch_accountant'], [$this->a]);
        $p = $this->profit($accA);
        $this->assertSame([$this->a], array_keys($p));
        $this->assertSame(['200.0000', '20.0000', '180.0000'], [$p[$this->a]['revenue'], $p[$this->a]['cogs'], $p[$this->a]['gross_profit']]);
        $this->as($accA)->getJson('/api/finance/branch-profit?from=2020-01-01&to=2030-01-01&branch_id='.$this->b)->assertForbidden();

        $all = $this->profit($this->finance);
        $this->assertSame('300.0000', $all[$this->b]['revenue']);
        $this->assertSame('500.0000', $this->as($this->finance)->getJson('/api/finance/branch-profit?from=2020-01-01&to=2030-01-01')->json('total.revenue'));
        $this->assertSame([$this->b], array_keys($this->profit($this->finance, '&branch_id='.$this->b)));

        // trial balance and ledger of the branch only
        $tb = collect($this->as($accA)->getJson('/api/finance/trial-balance')->assertOk()->json('data'))->keyBy('account_code');
        $this->assertSame('200.0000', $tb['4000']['credit']);
        $this->assertSame('500.0000', collect($this->as($this->finance)->getJson('/api/finance/trial-balance')->json('data'))->firstWhere('account_code', '4000')['credit']);
        $this->assertCount(1, $this->as($accA)->getJson('/api/finance/ledger?account=4000&from=2020-01-01&to=2030-01-01')->assertOk()->json('lines'));

        // invoices and debts of the branch only
        $this->assertSame([$invA], collect($this->as($accA)->getJson('/api/receivables/invoices')->assertOk()->json('data'))->pluck('id')->all());
        $this->as($accA)->getJson("/api/sales-invoices/{$invB}")->assertForbidden();
        $this->as($accA)->getJson("/api/sales-invoices/{$invA}")->assertOk();
        $ageing = $this->as($accA)->getJson('/api/receivables/ageing')->assertOk()->json('customers');
        $this->assertSame([['Al Noor', '200.0000']], array_map(fn ($c) => [$c['customer'], $c['total']], $ageing));

        // whole-company screens are closed to branch staff
        foreach (['/api/payables/suppliers', '/api/payables/invoices', '/api/finance/bank-statements', '/api/finance/fx-revaluations', '/api/sales-invoices/verify-chain'] as $url) {
            $this->as($accA)->getJson($url)->assertForbidden();
        }

        // the branch collects its own invoice in cash: the money is in the branch's till
        $this->as($accA)->postJson('/api/receivables/receipts', ['customer_id' => $this->customer, 'received_on' => CarbonImmutable::today()->format('Y-m-d'), 'method' => 'cash', 'reference' => 'R-1', 'allocations' => [['invoice_id' => $invB, 'amount' => '10']]])->assertForbidden();
        $this->as($accA)->postJson('/api/receivables/receipts', ['customer_id' => $this->customer, 'received_on' => CarbonImmutable::today()->format('Y-m-d'), 'method' => 'cash', 'reference' => 'R-1', 'allocations' => [['invoice_id' => $invA, 'amount' => '200']]])->assertCreated();
        $tills = $this->as($accA)->getJson('/api/finance/bank-accounts')->assertOk()->json('data');
        $this->assertSame([['till', $this->a, '200.0000']], array_map(fn ($t) => [$t['kind'], $t['branch_id'], $t['balance']], $tills));   // no bank accounts for branch staff
        $cash = collect($this->as($this->finance)->getJson('/api/finance/bank-accounts')->json('data'))->firstWhere('kind', 'cash');
        $this->assertSame('200.0000', collect($cash['by_branch'])->firstWhere('branch_id', $this->a)['balance']);

        // the branch manager sees the branch's sales only
        $manager = $this->userWith(['branch_manager'], [$this->a]);
        $this->assertSame(1, $this->as($manager)->getJson('/api/reports/sales-summary?period=month')->assertOk()->json('totals.count'));
        $this->assertSame(2, $this->as($this->finance)->getJson('/api/reports/sales-summary?period=month')->json('totals.count'));
    }

    public function test_branch_hr_manages_only_its_staff_and_payroll_is_booked_per_branch(): void
    {
        $hrA = $this->userWith(['branch_hr'], [$this->a]);
        $hr = $this->userWith(['hr_manager']);
        $empA = $this->as($hrA)->postJson('/api/hr/employees', ['code' => 'A1', 'name' => 'Ali', 'hire_date' => '2020-01-01', 'warehouse_id' => $this->a, 'base_salary' => '3000'])->assertCreated()->json('id');
        $this->as($hrA)->postJson('/api/hr/employees', ['code' => 'X1', 'name' => 'Other', 'hire_date' => '2020-01-01', 'warehouse_id' => $this->b, 'base_salary' => '1'])->assertForbidden();
        $this->as($hrA)->postJson('/api/hr/employees', ['code' => 'X2', 'name' => 'Nowhere', 'hire_date' => '2020-01-01'])->assertUnprocessable();
        $empB = $this->as($hr)->postJson('/api/hr/employees', ['code' => 'B1', 'name' => 'Badr', 'hire_date' => '2020-01-01', 'warehouse_id' => $this->b, 'base_salary' => '2000'])->assertCreated()->json('id');
        $office = $this->as($hr)->postJson('/api/hr/employees', ['code' => 'H1', 'name' => 'Head office', 'hire_date' => '2020-01-01', 'base_salary' => '5000'])->assertCreated()->json('id');

        $this->assertSame(['A1'], collect($this->as($hrA)->getJson('/api/hr/employees')->assertOk()->json('data'))->pluck('code')->all());
        $this->assertCount(3, $this->as($hr)->getJson('/api/hr/employees')->json('data'));
        $day = CarbonImmutable::today()->format('Y-m-d');
        $this->as($hrA)->putJson('/api/hr/attendance', ['date' => $day, 'records' => [['employee_id' => $empB, 'status' => 'absent']]])->assertForbidden();
        $this->as($hrA)->putJson('/api/hr/attendance', ['date' => $day, 'records' => [['employee_id' => $empA, 'status' => 'present']]])->assertOk();
        $this->assertSame([$empA], collect($this->as($hrA)->getJson('/api/hr/attendance?date='.$day)->json('data'))->pluck('employee_id')->all());
        $this->as($hrA)->postJson('/api/hr/leaves', ['employee_id' => $empB, 'type' => 'annual', 'from_date' => $day, 'to_date' => $day])->assertForbidden();
        $this->as($hrA)->putJson("/api/hr/employees/{$office}", ['name' => 'x'])->assertForbidden();
        $this->as($hrA)->postJson("/api/hr/employees/{$empB}/terminate", ['terminated_on' => $day])->assertForbidden();
        $this->as($hrA)->postJson('/api/hr/contracts', ['employee_id' => $empB, 'reference' => 'C-X', 'type' => 'permanent', 'start_date' => '2024-01-01', 'base_salary' => '9'])->assertForbidden();
        $this->assertSame(['A1-1'], collect($this->as($hrA)->getJson('/api/hr/contracts')->json('data'))->pluck('reference')->all());
        $this->as($hrA)->postJson('/api/hr/payroll', ['period' => CarbonImmutable::today()->subMonth()->format('Y-m')])->assertForbidden();   // runs are company-wide

        $period = CarbonImmutable::today()->subMonth()->format('Y-m');
        $run = $this->as($hr)->postJson('/api/hr/payroll', ['period' => $period])->assertCreated()->json('id');
        $this->as($hr)->postJson("/api/hr/payroll/{$run}/approve")->assertNoContent();

        // branch HR sees only its people's pay
        $view = $this->as($hrA)->getJson("/api/hr/payroll/{$run}")->assertOk()->json();
        $this->assertSame(['A1'], array_column($view['lines'], 'code'));
        $this->assertSame('3000.0000', $view['total_net']);
        $this->assertSame('3000.0000', $this->as($hrA)->getJson('/api/hr/payroll')->json('data.0.total_net'));
        $this->assertSame('10000.0000', $this->as($hr)->getJson('/api/hr/payroll')->json('data.0.total_net'));

        // salaries are an expense of the branch the employee works in (head office for the rest)
        $expense = DB::table('journal_lines')->where('account_code', config('erp.accounts.payroll_expense'))->get()->mapWithKeys(fn ($l) => [(int) ($l->warehouse_id ?? 0) => (int) $l->debit])->all();
        $this->assertSame([0 => 50000000, $this->a => 30000000, $this->b => 20000000], collect($expense)->sortKeys()->all());
        $p = $this->profit($this->userWith(['branch_accountant'], [$this->b]));
        $this->assertSame('2000.0000', $p[$this->b]['expenses']);
        $this->assertSame('-2000.0000', $p[$this->b]['net_profit']);

        $this->as($this->finance)->postJson("/api/hr/payroll/{$run}/pay", ['method' => 'cash', 'paid_on' => $day])->assertNoContent();
        $payable = DB::table('journal_lines')->where('account_code', config('erp.accounts.salaries_payable'))->groupBy('warehouse_id')->selectRaw('warehouse_id, SUM(debit) - SUM(credit) as net')->pluck('net', 'warehouse_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([0], array_values(array_unique($payable)));   // each branch's salaries cleared
    }

    public function test_branch_roles_must_be_given_a_branch_and_are_listed(): void
    {
        $admin = $this->userWith(['admin']);
        $labels = collect($this->as($admin)->getJson('/api/lookups')->json('roles'))->pluck('label', 'name');
        $this->assertSame(['مدير الفرع', 'محاسب الفرع', 'موارد بشرية للفرع'], [$labels['branch_manager'], $labels['branch_accountant'], $labels['branch_hr']]);

        $body = ['name' => 'B', 'email' => 'b@example.test', 'password' => 'Very-Str0ng-Pass1', 'roles' => ['branch_accountant']];
        $this->as($admin)->postJson('/api/admin/users', $body)->assertUnprocessable()->assertJsonValidationErrors('warehouses');
        $id = $this->as($admin)->postJson('/api/admin/users', $body + ['warehouses' => [$this->b]])->assertCreated()->json('id');
        $this->as($admin)->putJson("/api/admin/users/{$id}/access", ['roles' => ['branch_manager']])->assertUnprocessable();
        $this->as($admin)->putJson("/api/admin/users/{$id}/access", ['roles' => ['accountant']])->assertNoContent();   // the company accountant needs no branch
    }
}
