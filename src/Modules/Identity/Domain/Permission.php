<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

/** Every permission the system checks. Using an enum makes a typo a compile-time error, not a silent deny. */
enum Permission: string
{
    case MasterDataManage = 'master_data.manage';
    case InventoryView = 'inventory.view';
    case InventoryViewCost = 'inventory.view_cost';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryTransfer = 'inventory.transfer';
    case ShipmentsManage = 'shipments.manage';
    case ShipmentsCost = 'shipments.cost';
    case ShipmentsReceive = 'shipments.receive';
    case PurchaseOrdersCreate = 'purchase_orders.create';
    case PurchaseOrdersApprove = 'purchase_orders.approve';
    case PurchaseOrdersReceive = 'purchase_orders.receive';
    case SalesCreate = 'sales.create';
    case SalesApproveBelowMargin = 'sales.approve_below_margin';
    case SalesShip = 'sales.ship';
    case SalesInvoice = 'sales.invoice';
    case SalesCancel = 'sales.cancel';
    case FinanceView = 'finance.view';
    case SuppliersManage = 'suppliers.manage';
    case SupplierInvoicesRecord = 'supplier_invoices.record';
    case SupplierPaymentsMake = 'supplier_payments.make';
    case GrIrClear = 'grir.clear';
    case ShipmentsSettle = 'shipments.settle';
    case ReceiptsRecord = 'receipts.record';
    case SalesReturn = 'sales.return';
    case SalesOverrideCredit = 'sales.override_credit';
    case ReportsView = 'reports.view';
    case CurrenciesManage = 'currencies.manage';
    case BanksManage = 'banks.manage';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
    /** Without it a user only works in the warehouses assigned to them. */
    case WarehousesAll = 'warehouses.all';
}
