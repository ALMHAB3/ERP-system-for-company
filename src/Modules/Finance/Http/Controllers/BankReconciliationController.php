<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Application\BankReconciliation;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

/** Bank statements and their reconciliation with the ledger. */
final class BankReconciliationController extends ApiController
{
    private const SIGNED = 'regex:/^-?\d{1,13}(\.\d{1,4})?$/';

    public function index(Request $request, BankReconciliation $bank): JsonResponse
    {
        $d = $request->validate(['bank_account_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $bank->list(isset($d['bank_account_id']) ? (int) $d['bank_account_id'] : null)]);
    }

    public function store(Request $request, BankReconciliation $bank): JsonResponse
    {
        $d = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'reference' => ['required', 'string', 'max:60'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'opening_balance' => ['required', self::SIGNED], 'closing_balance' => ['required', self::SIGNED],
            'lines' => ['required', 'array', 'min:1', 'max:2000'], 'lines.*.date' => ['required', 'date_format:Y-m-d'],
            'lines.*.description' => ['required', 'string', 'max:200'], 'lines.*.amount' => ['required', self::SIGNED],
        ]);
        $lines = array_map(static fn (array $l) => ['date' => $l['date'], 'description' => $l['description'], 'amount' => Decimal::of($l['amount'])], $d['lines']);
        $id = $bank->import((int) $d['bank_account_id'], $d['reference'], new DateTimeImmutable($d['date']), Decimal::of($d['opening_balance']), Decimal::of($d['closing_balance']), $lines, $this->userId($request));

        return response()->json(['id' => $id], 201);
    }

    public function show(int $statement, BankReconciliation $bank): JsonResponse
    {
        return response()->json($bank->show($statement));
    }

    public function candidates(int $line, BankReconciliation $bank): JsonResponse
    {
        return response()->json(['data' => $bank->candidates($line)]);
    }

    public function match(Request $request, int $line, BankReconciliation $bank): JsonResponse
    {
        $d = $request->validate(['journal_line_id' => ['required', 'integer']]);
        $bank->match($line, (int) $d['journal_line_id'], $this->userId($request));

        return response()->json(null, 204);
    }

    public function unmatch(int $line, BankReconciliation $bank): JsonResponse
    {
        $bank->unmatch($line);

        return response()->json(null, 204);
    }

    public function book(Request $request, int $line, BankReconciliation $bank): JsonResponse
    {
        $d = $request->validate(['account' => ['required', 'string', 'exists:accounts,code']]);
        $bank->book($line, $d['account'], $this->userId($request));

        return response()->json(null, 204);
    }

    public function autoMatch(Request $request, int $statement, BankReconciliation $bank): JsonResponse
    {
        return response()->json(['matched' => $bank->autoMatch($statement, $this->userId($request))]);
    }

    public function reconcile(Request $request, int $statement, BankReconciliation $bank): JsonResponse
    {
        $bank->reconcile($statement, $this->userId($request));

        return response()->json(null, 204);
    }
}
