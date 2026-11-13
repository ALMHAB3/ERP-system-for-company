<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Finance\Domain\JournalRepository;

final class FinanceController
{
    public function trialBalance(JournalRepository $journals): JsonResponse
    {
        return response()->json(['data' => $journals->trialBalance()]);
    }
}
