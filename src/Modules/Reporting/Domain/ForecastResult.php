<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain;

final readonly class ForecastResult
{
    /** @param list<float> $forecast */
    public function __construct(
        public string $method,
        public array $forecast,
        /** Weighted absolute percentage error on a hold-out of the last periods, null when history is too short to test. */
        public ?float $wape,
        public float $mean,
        public float $stdDev,
        public int $periods,
    ) {}
}
