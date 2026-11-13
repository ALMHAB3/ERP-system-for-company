<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

/** How a sale is paid. Cash and bank transfer are paid at the moment of sale; credit is paid later and needs a registered customer. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Credit = 'credit';

    public function isImmediate(): bool
    {
        return $this !== self::Credit;
    }
}
