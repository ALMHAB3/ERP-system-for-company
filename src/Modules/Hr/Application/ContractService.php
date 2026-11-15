<?php

declare(strict_types=1);

namespace Modules\Hr\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Hr\Domain\Contract;
use Modules\Hr\Domain\ContractRepository;
use Modules\Hr\Domain\ContractStatus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Employment contracts: draft -> active -> ended (or cancelled while still a draft).
 * An employee never has two contracts in force on the same day. Activating a contract that starts after an
 * open-ended one closes the old contract the day before (a renewal or a raise); any other overlap is refused.
 */
final class ContractService
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly TransactionRunner $tx,
        private readonly AuditLogger $audit,
    ) {}

    public function draft(int $employeeId, string $reference, string $type, DateTimeImmutable $start, ?DateTimeImmutable $end, Fixed $baseSalary, Fixed $allowance, ?string $notes, ?int $userId): int
    {
        if (! in_array($type, Contract::TYPES, true)) {
            throw new DomainException("Unknown contract type {$type}.");
        }
        if ($end !== null && $end < $start) {
            throw new DomainException('A contract cannot end before it starts.');
        }
        if ($type === 'fixed_term' && $end === null) {
            throw new DomainException('A fixed-term contract needs an end date.');
        }
        if (! $baseSalary->isPositive() || $allowance->isNegative()) {
            throw new DomainException('The salary must be positive and the allowance cannot be negative.');
        }

        return $this->tx->run(function () use ($employeeId, $reference, $type, $start, $end, $baseSalary, $allowance, $notes, $userId): int {
            $employee = DB::table('employees')->where('id', $employeeId)->first() ?? throw new DomainException("Employee {$employeeId} not found.");
            if ($start->format('Y-m-d') < substr((string) $employee->hire_date, 0, 10)) {
                throw new DomainException('A contract cannot start before the hire date.');
            }
            $id = $this->contracts->insert(new Contract(null, $employeeId, $reference, $type, $start, $end, $baseSalary, $allowance, ContractStatus::Draft, $notes), $userId);
            $this->audit->record('contract.drafted', 'employee', $employeeId, ['contract_id' => $id, 'reference' => $reference, 'type' => $type, 'start' => $start->format('Y-m-d')], $userId);

            return $id;
        });
    }

    public function activate(int $contractId, ?int $userId): void
    {
        $this->tx->run(function () use ($contractId, $userId): void {
            $contract = $this->contracts->find($contractId, true) ?? throw new DomainException('Contract not found.');
            if ($contract->status !== ContractStatus::Draft) {
                throw new DomainException('Only a draft contract can be activated.');
            }
            foreach ($this->contracts->lockActive($contract->employeeId) as $current) {
                if (! $current->overlaps($contract->startDate, $contract->endDate)) {
                    continue;
                }
                // The new contract takes over from an earlier one that is still running on its start day.
                if ($current->startDate < $contract->startDate && ($current->endDate === null || $current->endDate >= $contract->startDate)) {
                    $this->contracts->setStatus((int) $current->id, ContractStatus::Ended, $contract->startDate->modify('-1 day'));

                    continue;
                }
                throw new DomainException("Contract {$current->reference} already covers these dates.");
            }
            foreach ($this->contracts->inForce($contract->employeeId, $contract->startDate, $contract->endDate ?? new DateTimeImmutable('9999-12-31')) as $past) {
                if ($past->status === ContractStatus::Ended && $past->overlaps($contract->startDate, $contract->endDate)) {
                    throw new DomainException("Contract {$past->reference} already covered part of these dates.");
                }
            }
            $this->contracts->setStatus($contractId, ContractStatus::Active);
            $this->syncEmployee($contract->employeeId);
            $this->audit->record('contract.activated', 'employee', $contract->employeeId, ['contract_id' => $contractId, 'reference' => $contract->reference], $userId);
        });
    }

    /** Ends an active contract on $endDate (its last day in force). */
    public function end(int $contractId, DateTimeImmutable $endDate, ?int $userId): void
    {
        $this->tx->run(function () use ($contractId, $endDate, $userId): void {
            $contract = $this->contracts->find($contractId, true) ?? throw new DomainException('Contract not found.');
            if ($contract->status !== ContractStatus::Active) {
                throw new DomainException('Only an active contract can be ended.');
            }
            if ($endDate < $contract->startDate || ($contract->endDate !== null && $endDate > $contract->endDate)) {
                throw new DomainException('The end date must fall inside the contract period.');
            }
            $this->contracts->setStatus($contractId, ContractStatus::Ended, $endDate);
            $this->audit->record('contract.ended', 'employee', $contract->employeeId, ['contract_id' => $contractId, 'end' => $endDate->format('Y-m-d')], $userId);
        });
    }

    public function cancel(int $contractId, ?int $userId): void
    {
        $this->tx->run(function () use ($contractId, $userId): void {
            $contract = $this->contracts->find($contractId, true) ?? throw new DomainException('Contract not found.');
            if ($contract->status !== ContractStatus::Draft) {
                throw new DomainException('Only a draft contract can be cancelled; end an active one instead.');
            }
            $this->contracts->setStatus($contractId, ContractStatus::Cancelled);
            $this->audit->record('contract.cancelled', 'employee', $contract->employeeId, ['contract_id' => $contractId], $userId);
        });
    }

    /** Keeps the salary shown on the employee card equal to the contract in force today (or the next one to start). */
    private function syncEmployee(int $employeeId): void
    {
        $today = CarbonImmutable::today();
        $current = null;
        foreach ($this->contracts->lockActive($employeeId) as $c) {
            if ($c->daysWithin($today, $today) === 1) {
                $current = $c;
                break;
            }
            $current ??= $c;
        }
        if ($current !== null) {
            DB::table('employees')->where('id', $employeeId)->update([
                'base_salary' => $current->baseSalary->units, 'monthly_allowance' => $current->monthlyAllowance->units, 'updated_at' => now(),
            ]);
        }
    }
}
