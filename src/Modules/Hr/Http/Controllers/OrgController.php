<?php

declare(strict_types=1);

namespace Modules\Hr\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hr\Application\ContractService;
use Modules\Hr\Application\OrgStructureService;
use Modules\Hr\Domain\Contract;
use Modules\Hr\Domain\ContractRepository;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

/** Organisation chart (departments, positions, reporting lines) and employment contracts. */
final class OrgController extends ApiController
{
    public function chart(OrgStructureService $org): JsonResponse
    {
        return response()->json($org->tree());
    }

    public function departments(OrgStructureService $org): JsonResponse
    {
        return response()->json(['data' => $org->departments(), 'positions' => $org->positions()]);
    }

    public function storeDepartment(Request $request, OrgStructureService $org): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:departments,name'], 'parent_id' => ['nullable', 'integer', 'exists:departments,id'], 'manager_id' => ['nullable', 'integer', 'exists:employees,id']]);

        return response()->json(['id' => $org->createDepartment($d['name'], $d['parent_id'] ?? null, $d['manager_id'] ?? null)], 201);
    }

    public function updateDepartment(Request $request, int $department, OrgStructureService $org): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:120', 'unique:departments,name,'.$department], 'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'], 'active' => ['sometimes', 'boolean']]);
        $org->updateDepartment($department, $d);

        return response()->json(null, 204);
    }

    public function storePosition(Request $request, OrgStructureService $org): JsonResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:120'], 'department_id' => ['nullable', 'integer', 'exists:departments,id'], 'headcount' => ['nullable', 'integer', 'min:1', 'max:10000']]);

        return response()->json(['id' => $org->createPosition($d['title'], $d['department_id'] ?? null, (int) ($d['headcount'] ?? 1))], 201);
    }

    public function assign(Request $request, int $employee, OrgStructureService $org): JsonResponse
    {
        $d = $request->validate(['department_id' => ['nullable', 'integer', 'exists:departments,id'], 'job_position_id' => ['nullable', 'integer', 'exists:job_positions,id'], 'manager_id' => ['nullable', 'integer', 'exists:employees,id']]);
        $org->assign($employee, $d['department_id'] ?? null, $d['job_position_id'] ?? null, $d['manager_id'] ?? null);

        return response()->json(null, 204);
    }

    public function contracts(Request $request, ContractRepository $contracts): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['nullable', 'integer'], 'expiring_within' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $data = isset($d['expiring_within'])
            ? $contracts->expiring(new DateTimeImmutable('today'), (int) $d['expiring_within'])
            : $contracts->listing(isset($d['employee_id']) ? (int) $d['employee_id'] : null);

        return response()->json(['data' => $data]);
    }

    public function storeContract(Request $request, ContractService $contracts): JsonResponse
    {
        $d = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'], 'reference' => ['required', 'string', 'max:40', 'unique:employment_contracts,reference'],
            'type' => ['required', 'in:'.implode(',', Contract::TYPES)], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'base_salary' => ['required', Decimal::RULE], 'monthly_allowance' => ['nullable', Decimal::RULE], 'notes' => ['nullable', 'string', 'max:250'], 'activate' => ['nullable', 'boolean'],
        ]);
        $id = $contracts->draft(
            (int) $d['employee_id'], $d['reference'], $d['type'], new DateTimeImmutable($d['start_date']), isset($d['end_date']) ? new DateTimeImmutable($d['end_date']) : null,
            Decimal::of($d['base_salary']), Decimal::of($d['monthly_allowance'] ?? '0'), $d['notes'] ?? null, $this->userId($request),
        );
        if ($d['activate'] ?? false) {
            $contracts->activate($id, $this->userId($request));
        }

        return response()->json(['id' => $id], 201);
    }

    public function activateContract(Request $request, int $contract, ContractService $contracts): JsonResponse
    {
        $contracts->activate($contract, $this->userId($request));

        return response()->json(null, 204);
    }

    public function endContract(Request $request, int $contract, ContractService $contracts): JsonResponse
    {
        $d = $request->validate(['end_date' => ['required', 'date_format:Y-m-d']]);
        $contracts->end($contract, new DateTimeImmutable($d['end_date']), $this->userId($request));

        return response()->json(null, 204);
    }

    public function cancelContract(Request $request, int $contract, ContractService $contracts): JsonResponse
    {
        $contracts->cancel($contract, $this->userId($request));

        return response()->json(null, 204);
    }
}
