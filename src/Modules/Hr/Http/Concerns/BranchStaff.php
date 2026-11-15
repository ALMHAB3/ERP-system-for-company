<?php

declare(strict_types=1);

namespace Modules\Hr\Http\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\AccessDenied;

/**
 * Branch HR and branch managers work only with the employees of their own branches (employees.warehouse_id).
 * Head-office staff (no branch) are visible to company-wide users only.
 */
trait BranchStaff
{
    /** Limits a query on employees (aliased by $column's table) to the user's branches. */
    protected function onlyBranchStaff(Builder $query, Request $request, string $column = 'e.warehouse_id'): Builder
    {
        $branches = $this->branchScope($request);

        return $branches === null ? $query : $query->whereIn($column, $branches === [] ? [0] : $branches);
    }

    /** Refuses when any of these employees is outside the user's branches. */
    protected function authorizeStaff(Request $request, int ...$employeeIds): void
    {
        $branches = $this->branchScope($request);
        if ($branches === null || $employeeIds === []) {
            return;
        }
        $outside = DB::table('employees')->whereIn('id', array_unique($employeeIds))
            ->where(fn ($q) => $q->whereNull('warehouse_id')->orWhereNotIn('warehouse_id', $branches === [] ? [0] : $branches))->exists();
        if ($outside) {
            throw new AccessDenied('This employee works in another branch.');
        }
    }
}
