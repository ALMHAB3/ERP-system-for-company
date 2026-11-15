<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\ExchangeRates;
use Modules\Shared\Domain\CompanyProfile;

/**
 * "Getting started" checklist for the administrator: what still has to be set up before real work.
 * Read-only; every step is done in its own screen.
 */
final class SetupStatusController
{
    public function __invoke(ExchangeRates $rates, CompanyProfile $profile): JsonResponse
    {
        $company = $profile->get();
        $base = $rates->base();
        $missingRates = array_values(array_filter($rates->activeCodes(), fn (string $c) => $c !== $base && ! DB::table('exchange_rates')->where('currency', $c)->exists()));
        $admins = DB::table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')->where('r.name', 'admin')->pluck('ru.user_id')->all();
        $steps = [
            'sample_data_removed' => ! DB::table('system_flags')->where('name', 'demo_data')->exists(),
            'company_details' => $company['tax_id'] !== '' && $company['address'] !== '',
            'branches' => DB::table('warehouses')->exists(),
            'users' => DB::table('users')->whereNotIn('id', $admins)->where('active', true)->exists(),
            'exchange_rates' => $missingRates === [],
            'bank_accounts' => DB::table('bank_accounts')->where('active', true)->exists(),
            'products' => DB::table('products')->exists(),
            'customers' => DB::table('customers')->where('is_walk_in', false)->exists(),
            'suppliers' => DB::table('suppliers')->exists(),
            'employees' => DB::table('employment_contracts')->where('status', 'active')->exists(),
        ];

        return response()->json([
            'ready' => ! in_array(false, $steps, true),
            'steps' => array_map(static fn (string $key, bool $done) => ['key' => $key, 'done' => $done], array_keys($steps), $steps),
            'missing_rates' => $missingRates,
        ]);
    }
}
