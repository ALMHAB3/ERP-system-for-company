<?php

declare(strict_types=1);

namespace Modules\Hr\Domain;

interface DepartmentRepository
{
    /** @param array{name:string,parent_id:?int,manager_id:?int} $data */
    public function insert(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void;

    public function exists(int $id): bool;

    /** @return array<int,?int> department id => parent id, for every department */
    public function parents(): array;

    /** @return list<object> every department with its manager's name and its number of active employees */
    public function all(): array;

    /** @return list<object> job positions with their department name and how many active employees hold them */
    public function positions(): array;

    public function insertPosition(string $title, ?int $departmentId, int $headcount): int;

    /** @return list<object> active employees with department, position and manager ids */
    public function staff(): array;
}
