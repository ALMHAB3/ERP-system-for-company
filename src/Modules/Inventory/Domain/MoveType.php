<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

enum MoveType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case SalesReturn = 'sales_return';
    case PurchaseReturn = 'purchase_return';

    public function isInbound(): bool
    {
        return match ($this) {
            self::Receipt, self::TransferIn, self::AdjustmentIn, self::SalesReturn => true,
            default => false,
        };
    }

    public function direction(): int
    {
        return $this->isInbound() ? 1 : -1;
    }
}
