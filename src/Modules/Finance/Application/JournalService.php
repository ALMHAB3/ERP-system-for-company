<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use DomainException;
use Modules\Finance\Domain\Exceptions\UnbalancedJournalEntry;
use Modules\Finance\Domain\Exceptions\UnknownAccount;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\JournalRepository;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/** Posts double-entry journals. Entries are immutable; mistakes are fixed by reversal entries. */
final class JournalService
{
    public function __construct(
        private readonly JournalRepository $journals,
        private readonly TransactionRunner $tx,
        private readonly ExchangeRates $rates,
        private readonly EntryBranchResolver $branches,
    ) {}

    public function post(JournalEntryData $entry): int
    {
        $this->assertValid($entry);
        $entry = $this->withBranch($this->withForeignAmounts($entry));

        return $this->tx->run(function () use ($entry): int {
            return $this->journals->findIdBySource($entry->sourceType, $entry->sourceId, $entry->rule)
                ?? $this->journals->insert($entry);
        });
    }

    public function reverse(int $entryId, string $reason): int
    {
        $original = $this->journals->find($entryId)
            ?? throw new UnknownAccount("Journal entry {$entryId} not found.");

        $lines = array_map(
            static fn (JournalLineData $l) => new JournalLineData($l->accountCode, $l->credit, $l->debit, $l->memo, $l->currency, $l->amountCurrency, null, $l->branchId),
            $original->lines,
        );

        return $this->tx->run(fn (): int => $this->journals->insert(new JournalEntryData(
            "Reversal of #{$entryId}: {$reason}", $lines, 'journal_entry', (string) $entryId, 'reversal',
        ), $entryId));
    }

    /**
     * A line on a foreign-currency bank account always records how much of that currency moved. Rules that know
     * the real amount set it themselves; otherwise it is derived from the base amount at the day's stored rate.
     * (No stored rate: the line stays in base currency only and is ignored by revaluation until a rate exists.)
     */
    private function withForeignAmounts(JournalEntryData $entry): JournalEntryData
    {
        $foreign = $this->journals->foreignCurrencyAccounts(
            array_values(array_unique(array_map(static fn (JournalLineData $l) => $l->accountCode, $entry->lines))), $this->rates->base(),
        );
        if ($foreign === []) {
            return $entry;
        }
        $lines = array_map(function (JournalLineData $l) use ($foreign, $entry): JournalLineData {
            $currency = $foreign[$l->accountCode] ?? null;
            if ($currency === null || $l->currency === $currency) {
                return $l;
            }
            try {
                $rate = $this->rates->rateFor($currency, $entry->postedAt);
            } catch (DomainException) {
                return $l;
            }

            return $l->inCurrency($currency, $l->debit->add($l->credit)->div($rate));
        }, $entry->lines);

        return new JournalEntryData($entry->description, $lines, $entry->sourceType, $entry->sourceId, $entry->rule, $entry->postedAt);
    }

    /** Lines a rule did not attribute to a branch take the branch of the source document (if it has one). */
    private function withBranch(JournalEntryData $entry): JournalEntryData
    {
        $branch = $this->branches->branchOf($entry->sourceType, $entry->sourceId);
        if ($branch === null) {
            return $entry;
        }
        $lines = array_map(static fn (JournalLineData $l) => $l->branchId === null ? $l->forBranch($branch) : $l, $entry->lines);

        return new JournalEntryData($entry->description, $lines, $entry->sourceType, $entry->sourceId, $entry->rule, $entry->postedAt);
    }

    private function assertValid(JournalEntryData $entry): void
    {
        if (count($entry->lines) < 2) {
            throw new UnbalancedJournalEntry('A journal entry needs at least two lines.');
        }
        $debits = Fixed::zero();
        $credits = Fixed::zero();
        foreach ($entry->lines as $line) {
            if ($line->debit->isNegative() || $line->credit->isNegative()) {
                throw new UnbalancedJournalEntry('Debit and credit amounts cannot be negative.');
            }
            if ($line->debit->isPositive() === $line->credit->isPositive()) {
                throw new UnbalancedJournalEntry('Each line must carry exactly one of debit or credit.');
            }
            $debits = $debits->add($line->debit);
            $credits = $credits->add($line->credit);
        }
        if (! $debits->equals($credits)) {
            throw new UnbalancedJournalEntry("Entry is unbalanced: debits {$debits} != credits {$credits}.");
        }

        $missing = $this->journals->missingAccounts(array_values(array_unique(array_map(
            static fn (JournalLineData $l) => $l->accountCode, $entry->lines,
        ))));
        if ($missing !== []) {
            throw new UnknownAccount('Unknown account code(s): '.implode(', ', $missing));
        }
    }
}
