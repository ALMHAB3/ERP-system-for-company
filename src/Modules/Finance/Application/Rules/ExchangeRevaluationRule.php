<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use DateTimeImmutable;
use Modules\Finance\Domain\Events\ExchangeRevalued;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/**
 * Unrealised exchange difference at period end. For every open foreign balance:
 * the account moves to its value at the closing rate, against "Unrealised exchange differences".
 * A payable that grew is a loss (Dr difference / Cr payables); a foreign bank balance that grew is a gain.
 */
class ExchangeRevaluationRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof ExchangeRevalued;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var ExchangeRevalued $event */
        $lines = $this->lines($event, false);

        return $lines === [] ? null : new JournalEntryData(
            'Exchange revaluation at '.$event->asOf->format('Y-m-d'), $lines, 'fx_revaluation', (string) $event->revaluationId, 'fx_revaluation', $this->endOfDay($event->asOf),
        );
    }

    /** @return list<JournalLineData> */
    protected function lines(ExchangeRevalued $event, bool $reversed): array
    {
        $difference = (string) config('erp.accounts.fx_unrealized');
        $lines = [];
        foreach ($event->adjustments as $adj) {
            if ($adj->difference->isZero()) {
                continue;
            }
            $amount = $adj->difference->abs();
            $debitAccount = $adj->difference->isPositive() !== $reversed;
            // A zero foreign amount on a foreign-currency account: only its base value changes, not the currency held.
            $currency = $adj->foreignAccount ? $adj->currency : null;
            $zero = $adj->foreignAccount ? Fixed::zero() : null;
            if ($debitAccount) {
                $lines[] = JournalLineData::debit($adj->accountCode, $amount, $adj->memo, $currency, $zero);
                $lines[] = JournalLineData::credit($difference, $amount, $adj->memo);
            } else {
                $lines[] = JournalLineData::debit($difference, $amount, $adj->memo);
                $lines[] = JournalLineData::credit($adj->accountCode, $amount, $adj->memo, $currency, $zero);
            }
        }

        return $lines;
    }

    protected function endOfDay(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(23, 59, 59);
    }
}
