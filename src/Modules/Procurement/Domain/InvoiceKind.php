<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

enum InvoiceKind: string
{
    case Goods = 'goods';
    case Freight = 'freight';
    case Customs = 'customs';
    case Clearance = 'clearance';
    case Insurance = 'insurance';
    /** Not stock-related: debits the expense account given on the invoice instead of GR/IR. */
    case Expense = 'expense';

    public function clearsGoodsReceived(): bool
    {
        return $this !== self::Expense;
    }
}
