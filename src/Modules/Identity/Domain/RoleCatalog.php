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
            'admin' => ['label' => 'System administrator', 'approval_level' => 3, 'permissions' => $all],
            'general_manager' => ['label' => 'General manager', 'approval_level' => 3, 'permissions' => array_values(array_filter(
                $all, static fn (Permission $p) => $p !== Permission::UsersManage,
            ))],
            'finance_manager' => ['label' => 'Finance manager', 'approval_level' => 2, 'permissions' => [
                Permission::FinanceView, Permission::AuditView, Permission::SalesInvoice, Permission::PurchaseOrdersApprove,
                Permission::ShipmentsCost, Permission::InventoryView, Permission::InventoryViewCost, Permission::WarehousesAll,
            ]],
            'purchasing_manager' => ['label' => 'Purchasing manager', 'approval_level' => 1, 'permissions' => [
                Permission::PurchaseOrdersCreate, Permission::PurchaseOrdersApprove, Permission::ShipmentsManage,
                Permission::MasterDataManage, Permission::InventoryView, Permission::InventoryViewCost, Permission::WarehousesAll,
            ]],
            'warehouse_clerk' => ['label' => 'Warehouse clerk', 'approval_level' => 0, 'permissions' => [
                Permission::InventoryView, Permission::InventoryAdjust, Permission::InventoryTransfer,
                Permission::ShipmentsReceive, Permission::PurchaseOrdersReceive, Permission::SalesShip,
            ]],
            'sales_rep' => ['label' => 'Sales representative', 'approval_level' => 0, 'permissions' => [
                Permission::SalesCreate, Permission::SalesCancel, Permission::InventoryView,
            ]],
        ];
    }
}
