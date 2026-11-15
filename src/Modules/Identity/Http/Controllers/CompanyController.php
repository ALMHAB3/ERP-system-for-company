<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Audit\Application\AuditLogger;
use Modules\Identity\Infrastructure\DbCompanyProfile;
use Modules\Shared\Http\ApiController;

/** The administrator edits the company's details and each branch's details on screen. */
final class CompanyController extends ApiController
{
    public function show(DbCompanyProfile $profile): JsonResponse
    {
        return response()->json($profile->get());
    }

    public function update(Request $request, DbCompanyProfile $profile, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'], 'tax_id' => ['nullable', 'string', 'max:60'], 'commercial_register' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:250'], 'phone' => ['nullable', 'string', 'max:60'], 'email' => ['nullable', 'email', 'max:190'],
        ]);
        DB::transaction(function () use ($d, $profile, $audit, $request) {
            $profile->save($d, $this->userId($request));
            $audit->record('company.updated', 'company', 1, $d);
        });

        return response()->json($profile->get());
    }

    /** Every branch and warehouse, active or not, with its details, manager and stock value. */
    public function branches(): JsonResponse
    {
        $rows = DB::table('warehouses as w')->leftJoin('employees as e', 'e.id', '=', 'w.manager_id')->orderBy('w.active', 'desc')->orderBy('w.code')
            ->select(['w.id', 'w.code', 'w.name', 'w.type', 'w.active', 'w.city', 'w.address', 'w.phone', 'w.commercial_register', 'w.manager_id', 'w.notes', 'e.name as manager'])
            ->selectSub(fn ($q) => $q->from('user_warehouses')->whereColumn('warehouse_id', 'w.id')->selectRaw('COUNT(*)'), 'users')
            ->get()->map(fn ($w) => array_merge((array) $w, ['active' => (bool) $w->active, 'users' => (int) $w->users]));

        return response()->json(['data' => $rows]);
    }

    public function updateBranch(Request $request, int $warehouse, AuditLogger $audit): JsonResponse
    {
        abort_unless(DB::table('warehouses')->where('id', $warehouse)->exists(), 404);
        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'], 'type' => ['sometimes', 'in:main,branch,transit,returns,damaged'],
            'city' => ['nullable', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:250'], 'phone' => ['nullable', 'string', 'max:60'],
            'commercial_register' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:250'],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (($d['active'] ?? true) === false) {
            // A branch with goods in it cannot be closed: move or adjust the stock first.
            $onHand = DB::table('stock_balances')->where('warehouse_id', $warehouse)->where('on_hand', '>', 0)->exists();
            abort_if($onHand, 422, 'This branch still holds stock; transfer or adjust it before closing the branch.');
        }
        DB::transaction(function () use ($d, $warehouse, $audit) {
            DB::table('warehouses')->where('id', $warehouse)->update($d + ['updated_at' => now()]);
            $audit->record('warehouse.updated', 'warehouse', $warehouse, $d);
        });

        return response()->json(null, 204);
    }
}
