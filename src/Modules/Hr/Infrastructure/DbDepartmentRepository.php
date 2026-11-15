<?php

declare(strict_types=1);

namespace Modules\Hr\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Hr\Domain\DepartmentRepository;

final class DbDepartmentRepository implements DepartmentRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function insert(array $data): int
    {
        return $this->db->table('departments')->insertGetId($data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function update(int $id, array $data): void
    {
        $this->db->table('departments')->where('id', $id)->update($data + ['updated_at' => now()]);
    }

    public function exists(int $id): bool
    {
        return $this->db->table('departments')->where('id', $id)->exists();
    }

    public function parents(): array
    {
        return $this->db->table('departments')->pluck('parent_id', 'id')->map(static fn ($p) => $p === null ? null : (int) $p)->all();
    }

    public function all(): array
    {
        return $this->db->table('departments as d')->leftJoin('employees as m', 'm.id', '=', 'd.manager_id')->orderBy('d.name')
            ->select(['d.id', 'd.name', 'd.parent_id', 'd.manager_id', 'd.active', 'm.name as manager'])
            ->selectSub(fn ($q) => $q->from('employees')->whereColumn('department_id', 'd.id')->where('status', 'active')->selectRaw('COUNT(*)'), 'employees')
            ->get()->all();
    }

    public function positions(): array
    {
        return $this->db->table('job_positions as p')->leftJoin('departments as d', 'd.id', '=', 'p.department_id')->orderBy('p.title')
            ->select(['p.id', 'p.title', 'p.department_id', 'p.headcount', 'd.name as department'])
            ->selectSub(fn ($q) => $q->from('employees')->whereColumn('job_position_id', 'p.id')->where('status', 'active')->selectRaw('COUNT(*)'), 'filled')
            ->get()->all();
    }

    public function insertPosition(string $title, ?int $departmentId, int $headcount): int
    {
        return $this->db->table('job_positions')->insertGetId(['title' => $title, 'department_id' => $departmentId, 'headcount' => $headcount, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function staff(): array
    {
        return $this->db->table('employees as e')->leftJoin('job_positions as p', 'p.id', '=', 'e.job_position_id')->where('e.status', 'active')->orderBy('e.name')
            ->get(['e.id', 'e.code', 'e.name', 'e.department_id', 'e.manager_id', 'e.job_title', 'p.title as position'])->all();
    }
}
