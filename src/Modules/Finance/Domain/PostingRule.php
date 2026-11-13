<?php

declare(strict_types=1);

namespace Modules\Finance\Domain;

use Modules\Shared\Domain\DomainEvent;

/** Strategy: turns one operational event into a balanced journal entry (or nothing). */
interface PostingRule
{
    public function handles(DomainEvent $event): bool;

    public function build(DomainEvent $event): ?JournalEntryData;
}
