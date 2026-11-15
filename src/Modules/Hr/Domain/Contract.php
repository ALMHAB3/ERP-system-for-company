<?php

declare(strict_types=1);

namespace Modules\Hr\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

final readonly class Contract
{
    public const TYPES = ['permanent', 'fixed_term', 'probation', 'part_time'];

    public function __construct(
        public ?int $id,
        public int $employeeId,
        public string $reference,
        public string $type,
        public DateTimeImmutable $startDate,
        public ?DateTimeImmutable $endDate,
        public Fixed $baseSalary,
        public Fixed $monthlyAllowance,
        public ContractStatus $status = ContractStatus::Draft,
        public ?string $notes = null,
    ) {}

    /** True when the two contracts share at least one day. */
    public function overlaps(DateTimeImmutable $start, ?DateTimeImmutable $end): bool
    {
        return ($this->endDate === null || $this->endDate >= $start) && ($end === null || $end >= $this->startDate);
    }

    /**
     * Number of days of [$from, $to] on which this contract is in force.
     */
    public function daysWithin(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        // Compared as plain dates, so callers may pass any DateTimeImmutable subclass with any time of day.
        $start = max($from->format('Y-m-d'), $this->startDate->format('Y-m-d'));
        $end = $this->endDate === null ? $to->format('Y-m-d') : min($to->format('Y-m-d'), $this->endDate->format('Y-m-d'));

        return $end < $start ? 0 : (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    }
}
