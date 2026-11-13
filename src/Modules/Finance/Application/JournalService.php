<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

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
    ) {}

    public function post(JournalEntryData $entry): int
    {
        $this->assertValid($entry);

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
            static fn (JournalLineData $l) => new JournalLineData($l->accountCode, $l->credit, $l->debit, $l->memo),
            $original->lines,
        );

        return $this->tx->run(fn (): int => $this->journals->insert(new JournalEntryData(
            "Reversal of #{$entryId}: {$reason}", $lines, 'journal_entry', (string) $entryId, 'reversal',
        ), $entryId));
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
