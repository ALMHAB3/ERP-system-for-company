<?php

declare(strict_types=1);

namespace Modules\Hr\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Hr\Domain\DepartmentRepository;
use Modules\Shared\Application\TransactionRunner;

/** The organisation chart: departments as a tree, their managers, job positions and reporting lines. */
final class OrgStructureService
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly TransactionRunner $tx,
        private readonly AuditLogger $audit,
    ) {}

    public function createDepartment(string $name, ?int $parentId, ?int $managerId): int
    {
        return $this->tx->run(function () use ($name, $parentId, $managerId): int {
            if ($parentId !== null && ! $this->departments->exists($parentId)) {
                throw new DomainException("Parent department {$parentId} not found.");
            }
            $id = $this->departments->insert(['name' => $name, 'parent_id' => $parentId, 'manager_id' => $managerId]);
            $this->audit->record('department.created', 'department', $id, ['name' => $name, 'parent_id' => $parentId]);

            return $id;
        });
    }

    /** @param array{name?:string,parent_id?:?int,manager_id?:?int,active?:bool} $changes */
    public function updateDepartment(int $id, array $changes): void
    {
        $this->tx->run(function () use ($id, $changes): void {
            if (! $this->departments->exists($id)) {
                throw new DomainException("Department {$id} not found.");
            }
            if (array_key_exists('parent_id', $changes) && $changes['parent_id'] !== null) {
                $this->assertNoCycle($id, (int) $changes['parent_id']);
            }
            $this->departments->update($id, $changes);
            $this->audit->record('department.updated', 'department', $id, $changes);
        });
    }

    public function createPosition(string $title, ?int $departmentId, int $headcount): int
    {
        if ($departmentId !== null && ! $this->departments->exists($departmentId)) {
            throw new DomainException("Department {$departmentId} not found.");
        }

        return $this->departments->insertPosition($title, $departmentId, max(1, $headcount));
    }

    /** Places an employee in the chart. An employee can be neither their own manager nor managed by someone below them. */
    public function assign(int $employeeId, ?int $departmentId, ?int $positionId, ?int $managerId): void
    {
        $this->tx->run(function () use ($employeeId, $departmentId, $positionId, $managerId): void {
            if ($departmentId !== null && ! $this->departments->exists($departmentId)) {
                throw new DomainException("Department {$departmentId} not found.");
            }
            for ($up = $managerId, $guard = 0; $up !== null; $up = DB::table('employees')->where('id', $up)->value('manager_id'), $guard++) {
                if ((int) $up === $employeeId || $guard > 1000) {
                    throw new DomainException('This reporting line would form a loop.');
                }
            }
            DB::table('employees')->where('id', $employeeId)->update(['department_id' => $departmentId, 'job_position_id' => $positionId, 'manager_id' => $managerId, 'updated_at' => now()]);
            $this->audit->record('employee.assigned', 'employee', $employeeId, ['department_id' => $departmentId, 'job_position_id' => $positionId, 'manager_id' => $managerId]);
        });
    }

    /**
     * The chart as a tree: each department with its manager, its people and its sub-departments.
     *
     * @return array{departments:list<array<string,mixed>>,unassigned:list<array<string,mixed>>}
     */
    public function tree(): array
    {
        $staff = ['' => []];
        foreach ($this->departments->staff() as $e) {
            $staff[(string) ($e->department_id ?? '')][] = ['id' => (int) $e->id, 'code' => $e->code, 'name' => $e->name, 'position' => $e->position, 'manager_id' => $e->manager_id === null ? null : (int) $e->manager_id];
        }
        $children = [];
        foreach ($this->departments->all() as $d) {
            $children[(string) ($d->parent_id ?? '')][] = $d;
        }
        $build = function (string $parent, array $seen = []) use (&$build, $children, $staff): array {
            $nodes = [];
            foreach ($children[$parent] ?? [] as $d) {
                $id = (string) $d->id;
                $own = $staff[$id] ?? [];
                $subs = isset($seen[$id]) ? [] : $build($id, $seen + [$id => true]);
                $nodes[] = [
                    'id' => (int) $d->id, 'name' => $d->name, 'active' => (bool) $d->active, 'manager_id' => $d->manager_id === null ? null : (int) $d->manager_id, 'manager' => $d->manager,
                    'employees' => $own, 'headcount' => count($own) + array_sum(array_column($subs, 'headcount')), 'children' => $subs,
                ];
            }

            return $nodes;
        };

        return ['departments' => $build(''), 'unassigned' => $staff['']];
    }

    /** @return list<object> */
    public function departments(): array
    {
        return $this->departments->all();
    }

    /** @return list<object> */
    public function positions(): array
    {
        return $this->departments->positions();
    }

    private function assertNoCycle(int $departmentId, int $newParentId): void
    {
        $parents = $this->departments->parents();
        if (! array_key_exists($newParentId, $parents)) {
            throw new DomainException("Parent department {$newParentId} not found.");
        }
        for ($up = $newParentId, $guard = 0; $up !== null; $up = $parents[$up] ?? null, $guard++) {
            if ($up === $departmentId || $guard > 1000) {
                throw new DomainException('A department cannot be placed under itself or under one of its own sub-departments.');
            }
        }
    }
}
