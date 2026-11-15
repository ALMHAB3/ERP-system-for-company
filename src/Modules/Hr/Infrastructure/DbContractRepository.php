<?php

declare(strict_types=1);

namespace Modules\Hr\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Hr\Domain\Contract;
use Modules\Hr\Domain\ContractRepository;
use Modules\Hr\Domain\ContractStatus;
use Modules\Shared\Domain\Fixed;

final class DbContractRepository implements ContractRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function insert(Contract $contract, ?int $userId): int
    {
        return $this->db->table('employment_contracts')->insertGetId([
            'employee_id' => $contract->employeeId, 'reference' => $contract->reference, 'type' => $contract->type,
            'start_date' => $contract->startDate->format('Y-m-d'), 'end_date' => $contract->endDate?->format('Y-m-d'),
            'base_salary' => $contract->baseSalary->units, 'monthly_allowance' => $contract->monthlyAllowance->units,
            'status' => $contract->status->value, 'notes' => $contract->notes, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function find(int $id, bool $lock = false): ?Contract
    {
        $row = $this->db->table('employment_contracts')->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function forEmployee(int $employeeId): array
    {
        return $this->db->table('employment_contracts')->where('employee_id', $employeeId)->orderBy('start_date')->orderBy('id')->get()->map($this->hydrate(...))->all();
    }

    public function inForce(int $employeeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->db->table('employment_contracts')->where('employee_id', $employeeId)
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Ended->value])
            ->where('start_date', '<=', $to->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->format('Y-m-d')))
            ->orderBy('start_date')->orderBy('id')->get()->map($this->hydrate(...))->all();
    }

    public function lockActive(int $employeeId): array
    {
        return $this->db->table('employment_contracts')->where('employee_id', $employeeId)->where('status', ContractStatus::Active->value)
            ->orderBy('start_date')->lockForUpdate()->get()->map($this->hydrate(...))->all();
    }

    public function setStatus(int $id, ContractStatus $status, ?DateTimeImmutable $endDate = null): void
    {
        $this->db->table('employment_contracts')->where('id', $id)->update(
            ['status' => $status->value, 'updated_at' => now()] + ($endDate === null ? [] : ['end_date' => $endDate->format('Y-m-d')]),
        );
    }

    public function listing(?int $employeeId = null): array
    {
        return $this->db->table('employment_contracts as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->when($employeeId !== null, fn ($q) => $q->where('c.employee_id', $employeeId))
            ->orderByDesc('c.start_date')->orderByDesc('c.id')->limit(500)->get(['c.*', 'e.name as employee', 'e.code as employee_code'])
            ->map($this->present(...))->all();
    }

    public function expiring(DateTimeImmutable $today, int $days): array
    {
        return $this->db->table('employment_contracts as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where('c.status', ContractStatus::Active->value)->whereNotNull('c.end_date')
            ->whereBetween('c.end_date', [$today->format('Y-m-d'), $today->modify("+{$days} days")->format('Y-m-d')])
            ->orderBy('c.end_date')->get(['c.*', 'e.name as employee', 'e.code as employee_code'])->map($this->present(...))->all();
    }

    /** @return array<string,mixed> */
    private function present(object $c): array
    {
        return [
            'id' => (int) $c->id, 'employee_id' => (int) $c->employee_id, 'employee' => $c->employee, 'employee_code' => $c->employee_code, 'reference' => $c->reference, 'type' => $c->type,
            'start_date' => substr((string) $c->start_date, 0, 10), 'end_date' => $c->end_date === null ? null : substr((string) $c->end_date, 0, 10),
            'base_salary' => (string) Fixed::units((int) $c->base_salary), 'monthly_allowance' => (string) Fixed::units((int) $c->monthly_allowance),
            'status' => $c->status, 'notes' => $c->notes,
        ];
    }

    private function hydrate(object $row): Contract
    {
        return new Contract(
            (int) $row->id, (int) $row->employee_id, $row->reference, $row->type,
            new DateTimeImmutable(substr((string) $row->start_date, 0, 10)), $row->end_date === null ? null : new DateTimeImmutable(substr((string) $row->end_date, 0, 10)),
            Fixed::units((int) $row->base_salary), Fixed::units((int) $row->monthly_allowance), ContractStatus::from($row->status), $row->notes,
        );
    }
}
