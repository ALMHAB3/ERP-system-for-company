<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\ExchangeRevaluation;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Http\ApiController;

final class FinanceController extends ApiController
{
    public function trialBalance(Request $request, JournalRepository $journals): JsonResponse
    {
        $d = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $journals->trialBalance(isset($d['as_of']) ? new DateTimeImmutable($d['as_of']) : null)]);
    }

    public function accounts(): JsonResponse
    {
        return response()->json(['data' => DB::table('accounts')->where('active', true)->orderBy('code')->get(['code', 'name', 'type'])]);
    }

    /** General ledger of one account: opening balance, every posted line in the period, running balance. */
    public function ledger(Request $request, JournalRepository $journals): JsonResponse
    {
        $d = $request->validate(['account' => ['required', 'string', 'max:16'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $statement = $journals->accountStatement($d['account'], new DateTimeImmutable($d['from']), new DateTimeImmutable($d['to']));
        abort_if($statement === null, 404);

        return response()->json($statement);
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
