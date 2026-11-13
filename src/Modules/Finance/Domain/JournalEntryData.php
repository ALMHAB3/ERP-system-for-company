<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use DateTimeImmutable;

final readonly class JournalEntryData
{
    /** @param list<JournalLineData> $lines */
    public function __construct(
        public string $description,
        public array $lines,
        /** Operational document that caused the entry, e.g. "stock_move". */
        public string $sourceType,
        public string $sourceId,
        /** Posting rule name; (sourceType, sourceId, rule) is unique, which makes posting idempotent. */
        public string $rule,
        public ?DateTimeImmutable $postedAt = null,
    ) {}
}
