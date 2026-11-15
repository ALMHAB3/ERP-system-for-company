<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\Events\ExchangeRevalued;
use Modules\Finance\Domain\JournalEntryData;
use Modules\Shared\Domain\DomainEvent;

/** The mirror entry on the first day of the next period, so realised differences are still measured against the booked rate. */
final class ExchangeRevaluationReversalRule extends ExchangeRevaluationRule
{
    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var ExchangeRevalued $event */
        $lines = $this->lines($event, true);

        return $lines === [] ? null : new JournalEntryData(
            'Reversal of exchange revaluation at '.$event->asOf->format('Y-m-d'), $lines, 'fx_revaluation', (string) $event->revaluationId, 'fx_revaluation_reversal', $event->reverseOn->setTime(0, 0),
        );
    }
}
