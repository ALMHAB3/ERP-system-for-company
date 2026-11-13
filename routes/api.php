<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
use Modules\Audit\Http\Middleware\AuditWriteRequests;
use Modules\Finance\Http\Controllers\FinanceController;
use Modules\Identity\Http\Controllers\AuthController;
use Modules\Identity\Http\Controllers\UserAdminController;
use Modules\Import\Http\Controllers\ShipmentController;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\MasterDataController;
use Modules\Procurement\Http\Controllers\PurchaseOrderController;
use Modules\Sales\Http\Controllers\CustomerController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::post('auth/token', [AuthController::class, 'issueToken'])->middleware('throttle:login')->name('auth.token');

Route::middleware(['auth:sanctum', 'throttle:api', AuditWriteRequests::class])->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::delete('auth/token', [AuthController::class, 'revokeToken'])->name('auth.revoke');

    Route::middleware('permission:users.manage')->prefix('admin')->group(function () {
        Route::post('users', [UserAdminController::class, 'store'])->name('admin.users.store');
        Route::put('users/{user}/access', [UserAdminController::class, 'updateAccess'])->whereNumber('user')->name('admin.users.access');
    });

    Route::middleware('permission:master_data.manage')->group(function () {
        Route::post('products', [MasterDataController::class, 'storeProduct'])->name('products.store');
        Route::post('warehouses', [MasterDataController::class, 'storeWarehouse'])->name('warehouses.store');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::put('prices', [CustomerController::class, 'setPrice'])->name('prices.set');
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('balances', [InventoryController::class, 'balances'])->middleware('permission:inventory.view')->name('balances');
        Route::get('atp', [InventoryController::class, 'atp'])->middleware('permission:inventory.view')->name('atp');
        Route::post('adjustments', [InventoryController::class, 'adjust'])->middleware('permission:inventory.adjust')->name('adjust');
        Route::post('transfers', [InventoryController::class, 'transfer'])->middleware('permission:inventory.transfer')->name('transfer');
    });

    Route::prefix('shipments')->name('shipments.')->whereNumber('shipment')->group(function () {
        Route::post('/', [ShipmentController::class, 'store'])->middleware('permission:shipments.manage')->name('store');
        Route::get('{shipment}', [ShipmentController::class, 'show'])->middleware('permission:shipments.manage')->name('show');
        Route::post('{shipment}/finalize', [ShipmentController::class, 'finalize'])->middleware('permission:shipments.cost')->name('finalize');
        Route::post('{shipment}/receive', [ShipmentController::class, 'receive'])->middleware('permission:shipments.receive')->name('receive');
    });

    Route::prefix('purchase-orders')->name('purchase_orders.')->whereNumber('order')->group(function () {
        Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.create')->name('store');
        Route::post('{order}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_orders.create')->name('submit');
        Route::post('{order}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase_orders.approve')->name('approve');
        Route::post('{order}/receive', [PurchaseOrderController::class, 'receive'])->middleware('permission:purchase_orders.receive')->name('receive');
    });

    Route::prefix('sales-orders')->name('sales_orders.')->whereNumber('order')->group(function () {
        Route::post('/', [SalesOrderController::class, 'store'])->middleware('permission:sales.create')->name('store');
        Route::get('{order}', [SalesOrderController::class, 'show'])->middleware('permission:sales.create')->name('show');
        Route::post('{order}/ship', [SalesOrderController::class, 'ship'])->middleware('permission:sales.ship')->name('ship');
        Route::post('{order}/invoice', [SalesOrderController::class, 'invoice'])->middleware('permission:sales.invoice')->name('invoice');
        Route::post('{order}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.cancel')->name('cancel');
    });

    Route::get('finance/trial-balance', [FinanceController::class, 'trialBalance'])->middleware('permission:finance.view')->name('finance.trial_balance');

    Route::middleware('permission:audit.view')->group(function () {
        Route::get('audit-logs', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit-logs/verify', [AuditController::class, 'verify'])->name('audit.verify');
    });
});
