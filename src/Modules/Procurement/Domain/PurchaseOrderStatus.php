<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
