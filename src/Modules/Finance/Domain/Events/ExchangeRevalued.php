<?php

declare(strict_types=1);

namespace Modules\Finance\Domain\Events;

use DateTimeImmutable;
use Modules\Shared\Domain\DomainEvent;

/** Open foreign balances were restated at the closing rate of $asOf; the adjustment is reversed on $reverseOn. */
final readonly class ExchangeRevalued implements DomainEvent
{
    /** @param list<RevaluationAdjustment> $adjustments */
    public function __construct(
        public int $revaluationId,
        public DateTimeImmutable $asOf,
        public DateTimeImmutable $reverseOn,
        public array $adjustments,
    ) {}
}
