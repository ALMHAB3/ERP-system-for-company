<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
use Modules\Audit\Http\Middleware\AuditWriteRequests;
use Modules\Finance\Http\Controllers\CurrencyController;
use Modules\Finance\Http\Controllers\FinanceController;
use Modules\Identity\Http\Controllers\AuthController;
use Modules\Identity\Http\Controllers\UserAdminController;
use Modules\Import\Http\Controllers\ShipmentController;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\MasterDataController;
use Modules\Procurement\Http\Controllers\PayablesController;
use Modules\Procurement\Http\Controllers\PurchaseOrderController;
use Modules\Reporting\Http\Controllers\ListController;
use Modules\Reporting\Http\Controllers\ReportController;
use Modules\Reporting\Http\Controllers\ShipmentPreviewController;
use Modules\Sales\Http\Controllers\CustomerController;
use Modules\Sales\Http\Controllers\PricingController;
use Modules\Sales\Http\Controllers\ReceivablesController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::post('auth/token', [AuthController::class, 'issueToken'])->middleware('throttle:login')->name('auth.token');

Route::middleware(['auth:sanctum', 'throttle:api', AuditWriteRequests::class])->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::get('lookups', [ListController::class, 'lookups'])->name('lookups');
    Route::get('shipments', [ListController::class, 'shipments'])->middleware('permission:shipments.manage|shipments.cost|shipments.receive|finance.view')->name('shipments.index');
    Route::get('purchase-orders', [ListController::class, 'purchaseOrders'])->middleware('permission:purchase_orders.create|purchase_orders.approve|purchase_orders.receive')->name('purchase_orders.index');
    Route::get('sales-orders', [ListController::class, 'salesOrders'])->middleware('permission:sales.create|sales.ship|sales.invoice')->name('sales_orders.index');
    Route::get('inventory/lots', [ListController::class, 'lots'])->middleware('permission:inventory.view')->name('inventory.lots');
    Route::get('admin/users', [ListController::class, 'users'])->middleware('permission:users.manage')->name('admin.users.index');
    Route::put('products/{product}', [ListController::class, 'updateProduct'])->whereNumber('product')->middleware('permission:master_data.manage')->name('products.update');
    Route::put('customers/{customer}', [ListController::class, 'updateCustomer'])->whereNumber('customer')->middleware('permission:master_data.manage')->name('customers.update');
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
        Route::get('pricing/suggestions', [PricingController::class, 'suggestions'])->name('pricing.suggestions');
        Route::post('pricing/apply', [PricingController::class, 'apply'])->name('pricing.apply');
    });

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('balances', [InventoryController::class, 'balances'])->middleware('permission:inventory.view')->name('balances');
        Route::get('atp', [InventoryController::class, 'atp'])->middleware('permission:inventory.view')->name('atp');
        Route::post('adjustments', [InventoryController::class, 'adjust'])->middleware('permission:inventory.adjust')->name('adjust');
        Route::post('transfers', [InventoryController::class, 'transfer'])->middleware('permission:inventory.transfer')->name('transfer');
    });

    Route::prefix('shipments')->name('shipments.')->whereNumber('shipment')->group(function () {
        Route::post('/', [ShipmentController::class, 'store'])->middleware('permission:shipments.manage')->name('store');
        Route::post('preview', ShipmentPreviewController::class)->middleware('permission:shipments.manage')->name('preview');
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

    Route::prefix('payables')->name('payables.')->group(function () {
        Route::get('suppliers', [PayablesController::class, 'suppliers'])->middleware('permission:finance.view')->name('suppliers.index');
        Route::post('suppliers', [PayablesController::class, 'storeSupplier'])->middleware('permission:suppliers.manage')->name('suppliers.store');
        Route::get('invoices', [PayablesController::class, 'invoices'])->middleware('permission:finance.view')->name('invoices.index');
        Route::post('invoices', [PayablesController::class, 'recordInvoice'])->middleware('permission:supplier_invoices.record')->name('invoices.store');
        Route::post('payments', [PayablesController::class, 'pay'])->middleware('permission:supplier_payments.make')->name('payments.store');
        Route::get('goods-received', [PayablesController::class, 'receiptStatus'])->middleware('permission:finance.view')->name('grir.status');
        Route::post('goods-received/clear', [PayablesController::class, 'clearReceipt'])->middleware('permission:grir.clear')->name('grir.clear');
        Route::get('ageing', [PayablesController::class, 'ageing'])->middleware('permission:reports.view')->name('ageing');
    });

    Route::prefix('receivables')->name('receivables.')->group(function () {
        Route::get('invoices', [ReceivablesController::class, 'invoices'])->middleware('permission:finance.view')->name('invoices.index');
        Route::post('receipts', [ReceivablesController::class, 'receive'])->middleware('permission:receipts.record')->name('receipts.store');
        Route::get('ageing', [ReceivablesController::class, 'ageing'])->middleware('permission:reports.view')->name('ageing');
    });
    Route::post('sales-orders/{order}/credit-notes', [ReceivablesController::class, 'creditNote'])->whereNumber('order')->middleware('permission:sales.return')->name('sales_orders.credit_note');

    Route::prefix('reports')->name('reports.')->middleware('permission:reports.view')->group(function () {
        Route::get('dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
        Route::get('charts', [ReportController::class, 'charts'])->name('charts');
        Route::get('expiring', [ReportController::class, 'expiring'])->name('expiring');
        Route::get('slow-moving', [ReportController::class, 'slowMoving'])->name('slow');
        Route::get('product-profitability', [ReportController::class, 'productProfit'])->name('product_profit');
        Route::get('shipment-profitability', [ReportController::class, 'shipmentProfit'])->name('shipment_profit');
        Route::get('forecast', [ReportController::class, 'forecast'])->name('forecast');
        Route::get('reorder-suggestions', [ReportController::class, 'reorder'])->name('reorder');
    });

    Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies.index');
    Route::get('currencies/history', [CurrencyController::class, 'history'])->middleware('permission:finance.view|currencies.manage')->name('currencies.history');
    Route::post('currencies/rates', [CurrencyController::class, 'store'])->middleware('permission:currencies.manage')->name('currencies.rates.store');

    Route::get('finance/trial-balance', [FinanceController::class, 'trialBalance'])->middleware('permission:finance.view')->name('finance.trial_balance');

    Route::middleware('permission:audit.view')->group(function () {
        Route::get('audit-logs', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit-logs/verify', [AuditController::class, 'verify'])->name('audit.verify');
    });
});
