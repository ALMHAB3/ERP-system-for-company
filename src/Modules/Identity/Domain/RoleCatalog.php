<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

/** Default roles shipped with the system. Approval level feeds the purchase-order approval ladder. */
final class RoleCatalog
{
    /** @return array<string, array{label: string, approval_level: int, permissions: list<Permission>}> */
    public static function defaults(): array
    {
        $all = Permission::cases();
        $P = Permission::class;

        return [
            'admin' => ['label' => 'مدير النظام', 'approval_level' => 3, 'permissions' => array_values(array_filter($all, static fn (Permission $p) => $p !== Permission::SalesCreate))],
            'general_manager' => ['label' => 'المدير العام', 'approval_level' => 3, 'permissions' => array_values(array_filter(
                $all, static fn (Permission $p) => $p !== Permission::UsersManage && $p !== Permission::IntegrationsManage && $p !== Permission::SalesCreate,
            ))],
            'finance_manager' => ['label' => 'المدير المالي', 'approval_level' => 2, 'permissions' => [
                Permission::FinanceView, Permission::AuditView, Permission::SalesInvoice, Permission::PurchaseOrdersApprove,
                Permission::ShipmentsCost, Permission::InventoryView, Permission::InventoryViewCost, Permission::WarehousesAll,
                Permission::SupplierInvoicesRecord, Permission::SupplierPaymentsMake, Permission::GrIrClear, Permission::ReceiptsRecord,
                Permission::SalesReturn, Permission::SalesOverrideCredit, Permission::ReportsView, Permission::SuppliersManage,
                Permission::CurrenciesManage, Permission::ShipmentsSettle, Permission::BanksManage,
                Permission::HrView, Permission::PayrollManage, Permission::PayrollPay,
            ]],
            'hr_manager' => ['label' => 'مدير الموارد البشرية', 'approval_level' => 1, 'permissions' => [
                Permission::HrView, Permission::HrManage, Permission::PayrollManage, Permission::InventoryView, Permission::WarehousesAll,
            ]],
            'accountant' => ['label' => 'محاسب', 'approval_level' => 1, 'permissions' => [
                Permission::FinanceView, Permission::ReceiptsRecord, Permission::SupplierInvoicesRecord, Permission::SalesInvoice,
                Permission::InventoryView, Permission::WarehousesAll, Permission::ReportsView,
            ]],
            'purchasing_manager' => ['label' => 'مدير المشتريات', 'approval_level' => 1, 'permissions' => [
                Permission::PurchaseOrdersCreate, Permission::PurchaseOrdersApprove, Permission::ShipmentsManage,
                Permission::MasterDataManage, Permission::InventoryView, Permission::InventoryViewCost, Permission::WarehousesAll,
                Permission::SuppliersManage, Permission::ReportsView,
            ]],
            'warehouse_clerk' => ['label' => 'أمين المخزن / عامل', 'approval_level' => 0, 'permissions' => [
                Permission::InventoryView, Permission::InventoryAdjust, Permission::InventoryTransfer,
                Permission::ShipmentsReceive, Permission::PurchaseOrdersReceive, Permission::SalesShip,
            ]],
            'sales_rep' => ['label' => 'مندوب مبيعات', 'approval_level' => 0, 'permissions' => [
                Permission::SalesCreate, Permission::SalesCancel, Permission::InventoryView, Permission::SalesReturn,
            ]],
        ];
    }
}
