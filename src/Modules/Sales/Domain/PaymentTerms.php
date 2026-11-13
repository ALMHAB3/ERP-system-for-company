<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

final readonly class PaymentTerms
{
    public function __construct(
        public PaymentMethod $method = PaymentMethod::Credit,
        /** Ledger account of the till or the chosen bank (cash / transfer only). */
        public ?string $cashAccount = null,
    ) {}
}
