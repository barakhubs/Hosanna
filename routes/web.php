<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\QuantityController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReloadController;
use App\Http\Controllers\UpgradeController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\SalaryRecordController;
use App\Http\Controllers\EmployeeBalanceController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SaleTemplateController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DevDatabaseController;
use App\Http\Controllers\ChargeController;
use App\Http\Controllers\SyncController;

Route::get('/', function () {
    return redirect('login');
    // return Inertia::render('Welcome', [
    //     'canLogin' => Route::has('login'),
    //     'canRegister' => Route::has('register'),
    // ]);
});

Route::get('/receipt/{id}', [SaleController::class, 'receipt'])->name('sales.receipt');
Route::get('/api/receipt-text-raw/{id}', [SaleController::class, 'apiReceipt']);
Route::get('/pending-sales-receipt/{contact_id}', [SaleController::class, 'pendingSalesReceipt']);

Route::get('/version', [UpgradeController::class, 'checkVersion']);
Route::post('/api/application-update', [UpgradeController::class, 'applicationUpdate'])->middleware('throttle:5,1');

// V2 Update routes (migration-based)
Route::post('/api/application-update-v2', [UpgradeController::class, 'applicationUpdateV2'])->middleware('throttle:5,1');


// Unified Sync API endpoints for offline-first InfoPOS app
// GET /api/sync?table=products - Fetch data
// POST /api/sync?table=sales - Push data
// GET /api/sync/health - Health check
// Everything except health needs a Sanctum token (php artisan pos:token)
Route::get('/api/sync/health', [SyncController::class, 'healthCheck'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('/api/sync', [SyncController::class, 'fetch']);
    Route::post('/api/sync', [SyncController::class, 'push']);

    // Store config endpoint
    Route::get('/api/stores/{storeId}', [SyncController::class, 'getStoreConfig']);
});

Route::middleware('auth')->group(function () {
    Route::get('/editor', function () {
        return Inertia::render('BlockEditor/Editor');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/summary', [DashboardController::class, 'getDashboardSummary'])->name('dashboard.summary');
    Route::post('/dashboard/sold-items-summary', [DashboardController::class, 'getSoldItemsSummary']);

    Route::get('/stores', [StoreController::class, 'index'])->name('store')->middleware('permission:stores');
    Route::post('/store', [StoreController::class, 'store'])->middleware('permission:stores');
    Route::post('/store/{id}', [StoreController::class, 'update'])->middleware('permission:stores');
    Route::post('/change-store', [StoreController::class, 'changeSelectedStore'])->name('change.store');

    Route::get('/collections', [CollectionController::class, 'index'])->name('collection')->middleware('permission:collections');
    Route::post('/collection', [CollectionController::class, 'store'])->middleware('permission:collections');
    Route::post('/collection/{id}', [CollectionController::class, 'update'])->middleware('permission:collections');
    Route::delete('/collections/{id}', [CollectionController::class, 'destroy'])->middleware('permission:collections');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index')->middleware('permission:products');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create')->middleware('permission:products,purchases');
    Route::get('/products/{id}/edit', [ProductController::class, 'find'])->name('products.find')->middleware('permission:products');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store')->middleware('permission:products');
    Route::post('/products/{id}', [ProductController::class, 'update'])->name('products.update')->middleware('permission:products');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy')->middleware('permission:products');
    Route::get('/products/search', [ProductController::class, 'searchProduct'])->name('products.search')->middleware('permission:products,pos,purchases');
    Route::post('/storebatch', [ProductController::class, 'storeNewBatch'])->name('products.newbatch')->middleware('permission:products,purchases');
    Route::post('/checkBatch', [ProductController::class, 'checkBatch'])->name('products.checkbatch')->middleware('permission:products,purchases');
    Route::post('/productbatch/{id}', [ProductController::class, 'updateBatch'])->name('products.updatebatch')->middleware('permission:products');
    Route::get('/getproducts/{store_id}', [ProductController::class, 'getProductsResponse'])->name('products.getproducts')->middleware('permission:products');
    Route::get('/product/{batch_id}/barcode', [ProductController::class, 'getBarcode'])->name('products.barcode')->middleware('permission:products');
    Route::get('/product/{batch_id}/barcode-v2', [ProductController::class, 'barcodeV2'])->name('products.barcode-v2')->middleware('permission:products');

    Route::get('/pos', [POSController::class, 'index'])->name('pos.index')->middleware('permission:pos');
    Route::get('/pos/{sale_id}/return', [POSController::class, 'returnIndex'])->name('pos.return')->middleware('permission:pos,sales,sold-items');
    Route::post('/pos/checkout', [POSController::class, 'checkout'])->name('pos.checkout')->middleware('permission:pos');
    Route::get('/pos/customer-display', [POSController::class, 'customerDisplay'])->middleware('permission:pos');
    Route::post('/pos/filter', [POSController::class, 'getProductsByFilter'])->middleware('permission:pos');
    Route::get('/pos/{id}/edit', [POSController::class, 'editSale'])->name('pos.edit')->middleware('permission:pos,sales');

    Route::get('/customers', [ContactController::class, 'index'])->defaults('type', 'customer')->name('customers.index')->middleware('permission:customers');
    Route::get('/vendors', [ContactController::class, 'index'])->defaults('type', 'vendor')->name('vendors.index')->middleware('permission:vendors');
    Route::post('/contact', [ContactController::class, 'store'])->name('contacts.store')->middleware('permission:customers,vendors,pos,purchases');
    Route::post('/contact/{id}', [ContactController::class, 'update'])->name('contacts.update')->middleware('permission:customers,vendors');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index')->middleware('permission:sales');
    Route::get('/sold-items', [SaleController::class, 'solditems'])->name('sales.items')->middleware('permission:sold-items');
    Route::get('/sold-items-summary', [SaleController::class, 'soldItemSummary'])->name('sales.items.summary')->middleware('permission:sold-items,sales');
    Route::delete('/sales/{id}', [SaleController::class, 'destroy'])->name('sales.destroy')->middleware('permission:sales');
    Route::get('/sale-notification/{id}', [SaleController::class, 'sendNotification']);

    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index')->middleware('permission:purchases');
    Route::get('/purchase/create', [PurchaseController::class, 'create'])->name('purchases.create')->middleware('permission:purchases');
    Route::post('/purchase/store', [PurchaseController::class, 'store'])->name('purchases.store')->middleware('permission:purchases');

    Route::post('/quantity/store', [QuantityController::class, 'store'])->name('quantity.store')->middleware('permission:products');
    Route::get('/quantity/{stock_id}/log', [QuantityController::class, 'getAdjustmentsLog'])->middleware('permission:products');

    Route::post('/customer-transaction', [TransactionController::class, 'storeCustomerTransaction'])->middleware('permission:payments,sales,customers');
    Route::post('/vendor-transaction', [TransactionController::class, 'storeVendorTransaction'])->middleware('permission:payments,purchases,vendors');
    Route::get('/payments/{type}', [TransactionController::class, 'viewPayments'])->middleware('permission:payments,customers,vendors'); //It will be purchases or sales

    Route::post('/getpayments/{type}', [TransactionController::class, 'findPayments'])->middleware('permission:payments,customers,vendors'); //It will be purchases or sales
    Route::post('/delete-payment/{type}', [TransactionController::class, 'deletePayment'])->middleware('permission:payments,sales,purchases'); //It will be purchases or sales
    Route::post('/getorderdetails/{type}', [ReportController::class, 'viewOrderDetails'])->middleware('permission:payments,sales,purchases,customers,vendors'); //It will be purchases or sales

    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index')->middleware('permission:settings');
    Route::post('/settings-update', [SettingController::class, 'update'])->name('settings.update')->middleware('permission:settings');
    Route::get('/settings/quote-template', [SettingController::class, 'quoteTemplate'])->middleware('permission:settings');
    Route::get('/settings/receipt-template', [SettingController::class, 'receiptTemplate'])->middleware('permission:settings');
    Route::get('/settings/barcode-template', [SettingController::class, 'barcodeTemplate'])->middleware('permission:settings');
    Route::get('/settings/custom-css', [SettingController::class, 'customCSS'])->middleware('permission:settings');
    Route::post('/settings/custom-css', [SettingController::class, 'updateCustomCSS'])->middleware('permission:settings');
    Route::post('/settings/module/{action}', [SettingController::class, 'updateModule'])->middleware('permission:settings');
    Route::post('/settings/get-template', [SettingController::class, 'getTemplate'])->name('settings.gettemplate')->middleware('permission:settings');
    Route::post('/settings/save-template', [SettingController::class, 'saveTemplate'])->name('settings.savetemplate')->middleware('permission:settings');

    Route::get('/api/barcode-template', [SettingController::class, 'getBarcodeTemplate'])->name('barcode.template.get')->middleware('permission:settings');
    Route::post('/api/barcode-template', [SettingController::class, 'saveBarcodeTemplate'])->name('barcode.template.save')->middleware('permission:settings');
    Route::post('/api/barcode-template/preview', [SettingController::class, 'renderBarcodeTemplatePreview'])->name('barcode.template.preview')->middleware('permission:settings');

    Route::get('/charges', [ChargeController::class, 'index'])->name('charges.index')->middleware('permission:charges,settings');
    Route::post('/charges', [ChargeController::class, 'store'])->name('charges.store')->middleware('permission:charges,settings');
    Route::put('/charges/{charge}', [ChargeController::class, 'update'])->name('charges.update')->middleware('permission:charges,settings');
    Route::delete('/charges/{charge}', [ChargeController::class, 'destroy'])->name('charges.destroy')->middleware('permission:charges,settings');
    Route::get('/api/charges/active', [ChargeController::class, 'getActive'])->name('charges.active')->middleware('permission:charges,settings,pos');
    Route::get('/api/charges/default', [ChargeController::class, 'getDefault'])->name('charges.default')->middleware('permission:charges,settings,pos');

    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index')->middleware('permission:expenses');
    Route::post('/expense', [ExpenseController::class, 'store'])->name('expenses.store')->middleware('permission:expenses');
    Route::post('/expense/{id}/delete', [ExpenseController::class, 'delete'])->name('expenses.delete')->middleware('permission:expenses');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index')->middleware('permission:employees');
    Route::post('/employee', [EmployeeController::class, 'store'])->name('employees.store')->middleware('permission:employees');
    Route::post('/employee/{id}', [EmployeeController::class, 'update'])->name('employees.update')->middleware('permission:employees');
    Route::post('/employee/{id}/delete', [EmployeeController::class, 'delete'])->name('employees.delete')->middleware('permission:employees');

    Route::get('/payroll', [SalaryRecordController::class, 'index'])->middleware('permission:payroll');
    Route::post('/salary-records', [SalaryRecordController::class, 'store'])->middleware('permission:payroll');
    Route::post('/salary/{id}/delete', [SalaryRecordController::class, 'delete'])->middleware('permission:payroll');

    Route::post('/employee-balance', [EmployeeBalanceController::class, 'store'])->middleware('permission:employees,payroll');
    Route::get('/employee-balance-log', [EmployeeBalanceController::class, 'balanceLog'])->middleware('permission:employees,payroll');

    Route::get('/users', [UserController::class, 'index'])->name('users.index')->middleware('user.role:admin,super-admin');
    Route::post('/user', [UserController::class, 'store'])->name('user.store')->middleware('user.role:admin,super-admin');
    Route::get('/user/role', [UserController::class, 'userRole'])->name('user.role')->middleware('user.role:admin,super-admin');
    Route::post('/user/role', [UserController::class, 'storeRole'])->name('user.storeRole')->middleware('user.role:admin,super-admin');
    Route::post('/user/{roleId}/role', [UserController::class, 'updateRole'])->name('user.updateRole')->middleware('user.role:admin,super-admin');
    Route::post('/user/{id}', [UserController::class, 'update'])->name('user.update')->middleware('user.role:admin,super-admin');
    Route::post('/users/{id}/deactivate', [UserController::class, 'userDeactivate'])->name('user.deactivate')->middleware('user.role:admin,super-admin');

    // Route::get('reports/daily',[ReportController::class, 'getDailyReport'])->name('reports.daily');
    Route::get('reports/dailycash', [ReportController::class, 'getDailyCashReport'])->name('reports.dailycash');
    Route::post('reports/dailycash', [ReportController::class, 'storeDailyCashReport'])->name('reports.store.dailycash');
    Route::get('reports/sales', [ReportController::class, 'getSalesReport'])->name('reports.sales');
    Route::get('reports/{customer}/customer-pending', [ReportController::class, 'getCustomerPendingReport']);
    Route::get('reports/{vendor}/vendor-pending', [ReportController::class, 'getVendorPendingReport']);
    Route::get('reports/{id}/customer', [ReportController::class, 'getCustomerReport'])->name('reports.customer');
    Route::get('reports/{id}/vendor', [ReportController::class, 'getVendorReport'])->name('reports.vendor');
    Route::get('reports/summary-report', [ReportController::class, 'getSummaryReport'])->name('reports.summary');

    Route::get('/reloads', [ReloadController::class, 'index'])->middleware('permission:reloads');
    Route::post('/reloads/{id}/update', [ReloadController::class, 'update'])->middleware('permission:reloads');

    // System routes: same admins who see these tools on the dashboard
    Route::middleware('user.role:admin,super-admin')->group(function () {
        Route::get('/link-storage', function () {
            Artisan::call('storage:link');
            return 'Linked with storage';
        });

        // Maintenance Routes
        Route::get('/maintenance', [UpgradeController::class, 'showMaintenance'])->name('maintenance.index');
        Route::post('/upload-v2', [UpgradeController::class, 'handleUploadV2'])->name('maintenance.upload');
        Route::get('/update', [UpgradeController::class, 'showUploadForm'])->name('upload.form');
        Route::post('/upload', [UpgradeController::class, 'handleUpload'])->name('upload.handle');

        // Database Management Routes
        Route::get('/api/maintenance/database/tables', [UpgradeController::class, 'getDatabaseTables']);
        Route::get('/api/maintenance/database/migrations', [UpgradeController::class, 'getMigrationStatus']);
        Route::post('/api/maintenance/database/migrate', [UpgradeController::class, 'runMigrations']);
        Route::post('/api/maintenance/database/seed', [UpgradeController::class, 'runSeeders']);
        Route::get('/api/maintenance/database/backup', [UpgradeController::class, 'backupDatabase']);

        Route::get('/clear-cache', function () {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            Artisan::call('event:clear');
            Artisan::call('optimize:clear');

            return 'All caches cleared and configurations updated!';
        });

        Route::get('/download-backup/{file}', [BackupController::class, 'download']);
        Route::get('/backup-now', [BackupController::class, 'downloadBackupZip']);
    });

    Route::get('/media', [MediaController::class, 'index'])->middleware('permission:media');
    Route::get('/migrate-images', [MediaController::class, 'migrateImages'])->middleware('permission:media');
    Route::post('/optimize-image', [MediaController::class, 'optimizeImages'])->middleware('permission:media');

    Route::get('/cheques', [ChequeController::class, 'index'])->name('cheques.index')->middleware('permission:cheques');
    Route::post('/cheques/store', [ChequeController::class, 'store'])->name('cheques.store')->middleware('permission:cheques');
    Route::post('/cheques/{cheque}/update', [ChequeController::class, 'update'])->name('cheques.update')->middleware('permission:cheques');
    Route::post('/cheques/{cheque}/destroy', [ChequeController::class, 'destroy'])->name('cheques.destroy')->middleware('permission:cheques');

    Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store')->middleware('permission:quotations,pos');
    Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index')->middleware('permission:quotations');
    Route::post('/quotations/{quotation}', [QuotationController::class, 'destroy'])->name('quotations.destroy')->middleware('permission:quotations');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show')->middleware('permission:quotations');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index')->middleware('permission:inventory');
    Route::post('/inventory-items', [InventoryController::class, 'store'])->name('inventory.store')->middleware('permission:inventory');
    Route::post('/inventory-items/{id}', [InventoryController::class, 'update'])->name('inventory.update')->middleware('permission:inventory');
    Route::delete('/inventory-items/{id}', [InventoryController::class, 'destroy'])->name('inventory.destroy')->middleware('permission:inventory');
    Route::post('/inventory-single-transaction', [InventoryController::class, 'inventorySingleTransactionStore'])->middleware('permission:inventory');
    Route::get('/inventory-purchase', [InventoryController::class, 'inventoryPurchase'])->middleware('permission:inventory');
    Route::post('/inventory-purchase', [InventoryController::class, 'inventoryPurchaseStore'])->middleware('permission:inventory');
    Route::get('/inventory-logs', [InventoryController::class, 'inventoryLogs'])->middleware('permission:inventory');

    Route::resource('sale-templates', SaleTemplateController::class)->middleware('permission:pos');

    Route::post('/test-mail', function (Request $request) {
        Mail::raw('Test email', function ($message) use ($request) {
            $message->to($request->input('test_mail'))->subject('Mail received');
        });
        return 'Mail sent';
    })->middleware('permission:settings');
});

require __DIR__ . '/auth.php';
