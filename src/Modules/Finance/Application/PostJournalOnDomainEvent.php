<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use Modules\Shared\Domain\DomainEvent;

/**
 * Observer: subscribed to operational events (stock moved, invoice issued, ...).
 * Runs inside the originating transaction, so an operational change and its
 * accounting entry commit or roll back together.
 */
final class PostJournalOnDomainEvent
{
    public function __construct(
        private readonly PostingRuleRegistry $rules,
        private readonly JournalService $journal,
    ) {}

    public function handle(DomainEvent $event): void
    {
        foreach ($this->rules->rulesFor($event) as $rule) {
            $entry = $rule->build($event);
            if ($entry !== null) {
                $this->journal->post($entry);
            }
        }
    }
}
