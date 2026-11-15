<?php

declare(strict_types=1);

namespace Modules\Hr\Domain;

use DateTimeImmutable;

interface ContractRepository
{
    public function insert(Contract $contract, ?int $userId): int;

    public function find(int $id, bool $lock = false): ?Contract;

    /** @return list<Contract> */
    public function forEmployee(int $employeeId): array;

    /**
     * Contracts that were in force (active or ended) on at least one day of [$from, $to], oldest first.
     *
     * @return list<Contract>
     */
    public function inForce(int $employeeId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** @return list<Contract> active contracts of the employee, locked for the current transaction */
    public function lockActive(int $employeeId): array;

    public function setStatus(int $id, ContractStatus $status, ?DateTimeImmutable $endDate = null): void;

    /**
     * The contract that sets each employee's pay today: the one in force today, else the latest active or ended one.
     *
     * @param  list<int>  $employeeIds
     * @return array<int,Contract> employee id => contract
     */
    public function current(array $employeeIds, DateTimeImmutable $today): array;

    /** @return list<array<string,mixed>> every contract with the employee's name, newest first */
    public function listing(?int $employeeId = null): array;

    /** Active contracts whose end date falls within the next $days days. @return list<array<string,mixed>> */
    public function expiring(DateTimeImmutable $today, int $days): array;
}
