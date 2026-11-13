<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

interface JournalRepository
{
    /** @return int|null id of the existing entry for this idempotency triple, if any */
    public function findIdBySource(string $sourceType, string $sourceId, string $rule): ?int;

    /** @param list<string> $codes */
    public function missingAccounts(array $codes): array;

    public function insert(JournalEntryData $entry, ?int $reversesEntryId = null): int;

    /** @return list<array{account_code:string,name:string,debit:string,credit:string}> */
    public function trialBalance(): array;

    public function find(int $id): ?JournalEntryData;
}
