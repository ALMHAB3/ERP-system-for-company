<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use DateTimeImmutable;

/** Persistence for bank statements and their matching to ledger lines. Amounts are integer 4-decimal units in the bank's currency. */
interface BankStatementRepository
{
    /** @return object{id:int,name:string,currency:string,gl_account:string,active:bool}|null */
    public function bankAccount(int $id): ?object;

    /** Closing balance of the latest statement of this bank account, or null when there is none. */
    public function lastClosingBalance(int $bankAccountId): ?int;

    /** @param list<array{line_date:string,description:string,amount:int}> $lines */
    public function insertStatement(int $bankAccountId, string $reference, string $date, int $opening, int $closing, array $lines, ?int $userId): int;

    /** @return list<object> */
    public function statements(?int $bankAccountId): array;

    /** Statement joined with its bank account (name, currency, gl_account). */
    public function statement(int $id, bool $lock = false): ?object;

    /** @return list<object> statement lines with the matched ledger line's date and description */
    public function lines(int $statementId): array;

    /** Statement line joined with its statement (status) and bank account (currency, gl_account). */
    public function line(int $lineId, bool $lock = false): ?object;

    /**
     * Ledger lines of the bank's account not yet matched to any statement line, as amounts in the bank's currency.
     *
     * @param  string|null  $foreignCurrency  null when the bank account is in the base currency
     * @return list<object{id:int,date:string,description:string,memo:?string,amount:int}>
     */
    public function unmatchedLedgerLines(string $glAccount, ?string $foreignCurrency, ?DateTimeImmutable $upTo = null, ?int $amount = null): array;

    /** Ledger balance of the bank's account in its own currency at the end of $asOf. */
    public function ledgerBalance(string $glAccount, ?string $foreignCurrency, DateTimeImmutable $asOf): int;

    public function ledgerLineId(string $sourceType, string $sourceId, string $rule, string $glAccount): ?int;

    public function setMatch(int $lineId, ?int $journalLineId, ?int $userId): void;

    public function markReconciled(int $statementId, ?int $userId): void;
}
