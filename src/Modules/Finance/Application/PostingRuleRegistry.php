<?php

declare(strict_types=1);

namespace Modules\Finance\Application;

use Modules\Finance\Domain\PostingRule;
use Modules\Shared\Domain\DomainEvent;

final class PostingRuleRegistry
{
    /** @param iterable<PostingRule> $rules */
    public function __construct(private readonly iterable $rules) {}

    /** @return list<PostingRule> */
    public function rulesFor(DomainEvent $event): array
    {
        $matching = [];
        foreach ($this->rules as $rule) {
            if ($rule->handles($event)) {
                $matching[] = $rule;
            }
        }

        return $matching;
    }
}
