<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use DateTimeImmutable;

interface JournalRepository
{
    /** @return int|null id of the existing entry for this idempotency triple, if any */
    public function findIdBySource(string $sourceType, string $sourceId, string $rule): ?int;

    /** @param list<string> $codes */
    public function missingAccounts(array $codes): array;

    /**
     * Ledger accounts among $codes that are held in a foreign currency (bank accounts not in the base currency).
     *
     * @param  list<string>  $codes
     * @return array<string,string> account code => currency
     */
    public function foreignCurrencyAccounts(array $codes, string $baseCurrency): array;

    public function insert(JournalEntryData $entry, ?int $reversesEntryId = null): int;

    /**
     * @param  DateTimeImmutable|null  $asOf  only entries posted on or before this day
     * @param  list<int>|null  $branches  only lines of these branches (null = the whole company)
     * @return list<array{account_code:string,name:string,debit:string,credit:string}>
     */
    public function trialBalance(?DateTimeImmutable $asOf = null, ?array $branches = null): array;

    /**
     * Revenue, cost of goods sold and other expenses per branch for a period (null branch = head office).
     *
     * @param  list<int>|null  $branches
     * @return list<array{branch_id:?int,revenue:int,cogs:int,expenses:int}>
     */
    public function profitByBranch(DateTimeImmutable $from, DateTimeImmutable $to, string $cogsAccount, ?array $branches = null): array;

    /**
     * Account statement (general ledger of one account) with a running balance.
     *
     * @return array{account:array{code:string,name:string,type:string},opening:string,closing:string,lines:list<array<string,mixed>>}|null null when the account does not exist
     */
    public function accountStatement(string $accountCode, DateTimeImmutable $from, DateTimeImmutable $to, ?array $branches = null): ?array;

    public function find(int $id): ?JournalEntryData;
}
