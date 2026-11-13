<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\ExchangeRates;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class CurrencyController extends ApiController
{
    public function index(ExchangeRates $rates): JsonResponse
    {
        $names = DB::table('currencies')->where('active', true)->pluck('name', 'code');

        return response()->json([
            'base' => $rates->base(),
            'currencies' => collect($rates->latest())->map(fn ($r, $code) => ['code' => $code, 'name' => $names[$code] ?? $code, 'is_base' => $code === $rates->base()] + $r)->values(),
        ]);
    }

    public function history(Request $request, ExchangeRates $rates): JsonResponse
    {
        $d = $request->validate(['currency' => ['nullable', 'string', 'size:3']]);
        $rows = DB::table('exchange_rates')->when(isset($d['currency']), fn ($q) => $q->where('currency', strtoupper($d['currency'])))
            ->orderByDesc('rate_date')->limit(200)->get(['currency', 'rate_date', 'rate', 'entered_by'])
            ->map(fn ($r) => ['currency' => $r->currency, 'date' => $r->rate_date, 'rate' => (string) Fixed::units((int) $r->rate), 'entered_by' => $r->entered_by]);

        return response()->json(['base' => $rates->base(), 'data' => $rows]);
    }

    public function store(Request $request, ExchangeRates $rates): JsonResponse
    {
        $d = $request->validate(['currency' => ['required', 'string', 'size:3'], 'rate' => ['required', Decimal::RULE], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $rates->set($d['currency'], Decimal::of($d['rate']), new DateTimeImmutable($d['date'] ?? 'today'), $this->userId($request));

        return response()->json(null, 201);
    }
}
