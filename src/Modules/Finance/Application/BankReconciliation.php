<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use DateTimeImmutable;
use DomainException;
use Modules\Audit\Application\AuditLogger;
use Modules\Finance\Application\Rules\BankStatementLineRule;
use Modules\Finance\Domain\BankStatementRepository;
use Modules\Finance\Domain\Events\BankStatementLineBooked;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Bank reconciliation. A statement is entered as the bank printed it; each of its lines is then matched to the
 * ledger line of the bank's account that records the same movement. Lines the ledger does not know yet (bank
 * charges, interest) are booked straight from the statement. A statement is reconciled once every line is matched;
 * ledger movements the bank has not processed yet (cheques in transit) stay open for the next statement.
 */
final class BankReconciliation
{
    /** Automatic matching only pairs a statement line with a ledger line dated within this many days of it. */
    private const AUTO_MATCH_DAYS = 7;

    public function __construct(
        private readonly BankStatementRepository $statements,
        private readonly JournalRepository $journals,
        private readonly ExchangeRates $rates,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{date:string,description:string,amount:Fixed}> $lines */
    public function import(int $bankAccountId, string $reference, DateTimeImmutable $date, Fixed $opening, Fixed $closing, array $lines, ?int $userId): int
    {
        if ($lines === []) {
            throw new DomainException('A bank statement needs at least one line.');
        }
        $total = Fixed::zero();
        $rows = [];
        foreach ($lines as $l) {
            if ($l['amount']->isZero()) {
                throw new DomainException('A statement line cannot have a zero amount.');
            }
            if ($l['date'] > $date->format('Y-m-d')) {
                throw new DomainException("Line \"{$l['description']}\" is dated after the statement date.");
            }
            $total = $total->add($l['amount']);
            $rows[] = ['line_date' => $l['date'], 'description' => $l['description'], 'amount' => $l['amount']->units];
        }
        if (! $opening->add($total)->equals($closing)) {
            throw new DomainException("The statement does not add up: opening {$opening} + movements {$total} is not the closing balance {$closing}.");
        }

        return $this->tx->run(function () use ($bankAccountId, $reference, $date, $opening, $closing, $rows, $userId): int {
            $bank = $this->statements->bankAccount($bankAccountId) ?? throw new DomainException("Bank account {$bankAccountId} not found.");
            $previous = $this->statements->lastClosingBalance($bankAccountId);
            if ($previous !== null && $previous !== $opening->units) {
                throw new DomainException('The opening balance must equal the closing balance of the previous statement ('.Fixed::units($previous).').');
            }
            $id = $this->statements->insertStatement($bankAccountId, $reference, $date->format('Y-m-d'), $opening->units, $closing->units, $rows, $userId);
            $this->audit->record('bank_statement.imported', 'bank_statement', $id, ['bank' => $bank->name, 'reference' => $reference, 'lines' => count($rows)], $userId);

            return $id;
        });
    }

    /**
     * Unmatched ledger lines with exactly the amount of this statement line, nearest date first.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(int $lineId): array
    {
        $line = $this->statements->line($lineId) ?? throw new DomainException('Statement line not found.');
        $found = $this->statements->unmatchedLedgerLines($line->gl_account, $this->foreignCurrency($line->currency), null, (int) $line->amount);
        usort($found, fn (object $a, object $b) => $this->daysApart($a->date, $line->line_date) <=> $this->daysApart($b->date, $line->line_date));

        return array_map(static fn (object $c) => ['journal_line_id' => $c->id, 'date' => $c->date, 'description' => $c->description, 'memo' => $c->memo, 'amount' => (string) Fixed::units($c->amount)], $found);
    }

    public function match(int $lineId, int $journalLineId, ?int $userId): void
    {
        $this->tx->run(function () use ($lineId, $journalLineId, $userId): void {
            $line = $this->openLine($lineId);
            if ($line->journal_line_id !== null) {
                throw new DomainException('This statement line is already matched; unmatch it first.');
            }
            foreach ($this->statements->unmatchedLedgerLines($line->gl_account, $this->foreignCurrency($line->currency), null, (int) $line->amount) as $candidate) {
                if ($candidate->id === $journalLineId) {
                    $this->statements->setMatch($lineId, $journalLineId, $userId);

                    return;
                }
            }
            throw new DomainException('That ledger line cannot be matched: it must be an unmatched movement of this bank account with exactly the same amount.');
        });
    }

    public function unmatch(int $lineId): void
    {
        $this->tx->run(function () use ($lineId): void {
            $this->openLine($lineId);
            $this->statements->setMatch($lineId, null, null);
        });
    }

    /** Pairs every statement line that has exactly one possible ledger line close to its date. @return int lines matched */
    public function autoMatch(int $statementId, ?int $userId): int
    {
        return $this->tx->run(function () use ($statementId, $userId): int {
            $statement = $this->openStatement($statementId);
            $matched = 0;
            foreach ($this->statements->lines($statementId) as $line) {
                if ($line->journal_line_id !== null) {
                    continue;
                }
                $near = array_values(array_filter(
                    $this->statements->unmatchedLedgerLines($statement->gl_account, $this->foreignCurrency($statement->currency), null, (int) $line->amount),
                    fn (object $c) => $this->daysApart($c->date, $line->line_date) <= self::AUTO_MATCH_DAYS,
                ));
                if (count($near) === 1) {
                    $this->statements->setMatch((int) $line->id, $near[0]->id, $userId);
                    $matched++;
                }
            }

            return $matched;
        });
    }

    /** Books a statement line the ledger does not have (charge, interest, ...) against $counterAccount and matches it. */
    public function book(int $lineId, string $counterAccount, ?int $userId): void
    {
        $this->tx->run(function () use ($lineId, $counterAccount, $userId): void {
            $line = $this->openLine($lineId);
            if ($line->journal_line_id !== null) {
                throw new DomainException('This statement line is already matched.');
            }
            if ($counterAccount === $line->gl_account || $this->journals->missingAccounts([$counterAccount]) !== []) {
                throw new DomainException("Account {$counterAccount} cannot be used as the counter account.");
            }
            $date = new DateTimeImmutable($line->line_date);
            $amount = Fixed::units((int) $line->amount);
            $foreign = $this->foreignCurrency($line->currency);
            $base = $foreign === null ? $amount->abs() : $amount->abs()->mul($this->rates->rateFor($foreign, $date));
            if (! $base->isPositive()) {
                throw new DomainException('The amount is too small to book.');
            }
            $this->events->dispatch(new BankStatementLineBooked($lineId, $line->gl_account, $counterAccount, $amount, $base, $foreign, $line->description, $date));
            $journalLineId = $this->statements->ledgerLineId('bank_statement_line', (string) $lineId, BankStatementLineRule::RULE, $line->gl_account)
                ?? throw new DomainException('The statement line could not be posted.');
            $this->statements->setMatch($lineId, $journalLineId, $userId);
            $this->audit->record('bank_statement.line_booked', 'bank_statement', (int) $line->bank_statement_id, ['line' => $lineId, 'account' => $counterAccount, 'amount' => (string) $amount], $userId);
        });
    }

    public function reconcile(int $statementId, ?int $userId): void
    {
        $this->tx->run(function () use ($statementId, $userId): void {
            $statement = $this->openStatement($statementId);
            if ((int) $statement->unmatched_count > 0) {
                throw new DomainException("{$statement->unmatched_count} statement line(s) are not matched yet.");
            }
            $this->statements->markReconciled($statementId, $userId);
            $this->audit->record('bank_statement.reconciled', 'bank_statement', $statementId, ['reference' => $statement->reference], $userId);
        });
    }

    /**
     * The statement, its lines and the reconciliation proof:
     * ledger balance - ledger movements not on a statement yet + statement lines not in the ledger yet = statement closing balance.
     *
     * @return array<string,mixed>
     */
    public function show(int $statementId): array
    {
        $s = $this->statements->statement($statementId) ?? throw new DomainException('Bank statement not found.');
        $foreign = $this->foreignCurrency($s->currency);
        $asOf = new DateTimeImmutable(substr((string) $s->statement_date, 0, 10));
        $lines = $this->statements->lines($statementId);
        $outstanding = $this->statements->unmatchedLedgerLines($s->gl_account, $foreign, $asOf);
        $ledger = $this->statements->ledgerBalance($s->gl_account, $foreign, $asOf);
        $outstandingTotal = array_sum(array_map(static fn (object $o) => $o->amount, $outstanding));
        $unbooked = array_sum(array_map(static fn (object $l) => $l->journal_line_id === null ? (int) $l->amount : 0, $lines));
        $adjusted = $ledger - $outstandingTotal + $unbooked;

        return [
            'statement' => $this->presentStatement($s),
            'lines' => array_map(static fn (object $l) => [
                'id' => (int) $l->id, 'date' => substr((string) $l->line_date, 0, 10), 'description' => $l->description, 'amount' => (string) Fixed::units((int) $l->amount),
                'matched' => $l->journal_line_id !== null, 'journal_line_id' => $l->journal_line_id === null ? null : (int) $l->journal_line_id,
                'entry_id' => $l->entry_id === null ? null : (int) $l->entry_id, 'entry_description' => $l->entry_description,
                'entry_date' => $l->entry_date === null ? null : substr((string) $l->entry_date, 0, 10),
            ], $lines),
            'outstanding_ledger_lines' => array_map(static fn (object $o) => ['journal_line_id' => $o->id, 'date' => $o->date, 'description' => $o->description, 'memo' => $o->memo, 'amount' => (string) Fixed::units($o->amount)], $outstanding),
            'summary' => [
                'ledger_balance' => (string) Fixed::units($ledger),
                'outstanding_ledger_total' => (string) Fixed::units($outstandingTotal),
                'unbooked_statement_total' => (string) Fixed::units($unbooked),
                'adjusted_ledger_balance' => (string) Fixed::units($adjusted),
                'statement_closing_balance' => (string) Fixed::units((int) $s->closing_balance),
                'difference' => (string) Fixed::units($adjusted - (int) $s->closing_balance),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(?int $bankAccountId): array
    {
        return array_map($this->presentStatement(...), $this->statements->statements($bankAccountId));
    }

    /** @return array<string,mixed> */
    private function presentStatement(object $s): array
    {
        return [
            'id' => (int) $s->id, 'bank_account_id' => (int) $s->bank_account_id, 'bank' => $s->bank_name, 'currency' => $s->currency, 'reference' => $s->reference,
            'date' => substr((string) $s->statement_date, 0, 10), 'opening_balance' => (string) Fixed::units((int) $s->opening_balance), 'closing_balance' => (string) Fixed::units((int) $s->closing_balance),
            'status' => $s->status, 'lines' => (int) $s->line_count, 'unmatched' => (int) $s->unmatched_count,
        ];
    }

    private function openStatement(int $statementId): object
    {
        $statement = $this->statements->statement($statementId, true) ?? throw new DomainException('Bank statement not found.');
        if ($statement->status !== 'open') {
            throw new DomainException('This statement is already reconciled and can no longer be changed.');
        }

        return $statement;
    }

    private function openLine(int $lineId): object
    {
        $line = $this->statements->line($lineId, true) ?? throw new DomainException('Statement line not found.');
        if ($line->status !== 'open') {
            throw new DomainException('This statement is already reconciled and can no longer be changed.');
        }

        return $line;
    }

    /** The bank's currency when it is not the base currency, else null. */
    private function foreignCurrency(string $currency): ?string
    {
        return strtoupper($currency) === $this->rates->base() ? null : strtoupper($currency);
    }

    private function daysApart(string $a, string $b): int
    {
        return (int) abs((new DateTimeImmutable(substr($a, 0, 10)))->diff(new DateTimeImmutable(substr($b, 0, 10)))->days);
    }
}
