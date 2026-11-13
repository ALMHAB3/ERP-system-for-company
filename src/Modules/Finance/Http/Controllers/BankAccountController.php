<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Application\CashAccounts;
use Modules\Shared\Http\ApiController;

final class BankAccountController extends ApiController
{
    public function index(CashAccounts $cash): JsonResponse
    {
        return response()->json(['data' => $cash->balances()]);
    }

    public function store(Request $request, CashAccounts $cash): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'account_number' => ['nullable', 'string', 'max:60'], 'currency' => ['required', 'string', 'size:3', 'exists:currencies,code']]);

        return response()->json(['id' => $cash->create($d['name'], $d['account_number'] ?? null, $d['currency'])], 201);
    }
}
