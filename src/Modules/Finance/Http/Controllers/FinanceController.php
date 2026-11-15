<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\ExchangeRevaluation;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;

final class FinanceController extends ApiController
{
    public function trialBalance(Request $request, JournalRepository $journals): JsonResponse
    {
        $d = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        $branches = $this->branchScope($request);

        return response()->json(['branches' => $branches, 'data' => $journals->trialBalance(isset($d['as_of']) ? new DateTimeImmutable($d['as_of']) : null, $branches)]);
    }

    public function accounts(): JsonResponse
    {
        return response()->json(['data' => DB::table('accounts')->where('active', true)->orderBy('code')->get(['code', 'name', 'type'])]);
    }

    /** General ledger of one account: opening balance, every posted line in the period, running balance. */
    public function ledger(Request $request, JournalRepository $journals): JsonResponse
    {
        $d = $request->validate(['account' => ['required', 'string', 'max:16'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $statement = $journals->accountStatement($d['account'], new DateTimeImmutable($d['from']), new DateTimeImmutable($d['to']), $this->branchScope($request));
        abort_if($statement === null, 404);

        return response()->json($statement);
    }

    /**
     * Profit and loss per branch: revenue, cost of goods sold, gross profit, other expenses (salaries ...), net profit.
     * "Head office" holds what belongs to no branch (imports, company-wide expenses).
     */
    public function branchProfit(Request $request, JournalRepository $journals): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $rows = $journals->profitByBranch(new DateTimeImmutable($d['from']), new DateTimeImmutable($d['to']), (string) config('erp.accounts.cost_of_goods_sold'), $this->branchScope($request));
        $names = DB::table('warehouses')->pluck('name', 'id');
        $f = static fn (int $u) => (string) Fixed::units($u);
        $out = array_map(static fn (array $r) => [
            'branch_id' => $r['branch_id'], 'branch' => $r['branch_id'] === null ? null : ($names[$r['branch_id']] ?? '#'.$r['branch_id']),
            'revenue' => $f($r['revenue']), 'cogs' => $f($r['cogs']), 'gross_profit' => $f($r['revenue'] - $r['cogs']),
            'expenses' => $f($r['expenses']), 'net_profit' => $f($r['revenue'] - $r['cogs'] - $r['expenses']),
        ], $rows);
        usort($out, static fn ($a, $b) => [$a['branch_id'] === null, $a['branch']] <=> [$b['branch_id'] === null, $b['branch']]);
        $total = static fn (callable $value) => $f(array_sum(array_map($value, $rows)));

        return response()->json(['from' => $d['from'], 'to' => $d['to'], 'data' => $out, 'total' => [
            'revenue' => $total(static fn ($r) => $r['revenue']), 'cogs' => $total(static fn ($r) => $r['cogs']),
            'gross_profit' => $total(static fn ($r) => $r['revenue'] - $r['cogs']), 'expenses' => $total(static fn ($r) => $r['expenses']),
            'net_profit' => $total(static fn ($r) => $r['revenue'] - $r['cogs'] - $r['expenses']),
        ]]);
    }

    public function revaluations(ExchangeRevaluation $fx): JsonResponse
    {
        return response()->json(['data' => $fx->history()]);
    }

    public function previewRevaluation(Request $request, ExchangeRevaluation $fx): JsonResponse
    {
        $d = $request->validate(['as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);

        return response()->json($fx->preview(new DateTimeImmutable($d['as_of'])));
    }

    public function revalue(Request $request, ExchangeRevaluation $fx): JsonResponse
    {
        $d = $request->validate(['as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);

        return response()->json($fx->run(new DateTimeImmutable($d['as_of']), $this->userId($request)), 201);
    }
}
