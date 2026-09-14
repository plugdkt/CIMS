<?php

use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Auth\PendingRoleController;
use App\Http\Controllers\Auth\PrivacyNoticeController;
use App\Http\Controllers\Auth\SsoCallbackController;
use App\Http\Controllers\Auth\SsoLoginController;
use App\Http\Controllers\Auth\SsoLogoutController;
use App\Http\Controllers\AccountDataController;
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisposalController;
use App\Http\Controllers\DocumentVerifyController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\GoodsReceiptItemController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LabController;
use App\Http\Controllers\LedgerExportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequisitionApprovalController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\RequisitionIssueController;
use App\Http\Controllers\RequisitionItemController;
use App\Http\Controllers\RequisitionReturnController;
use App\Http\Controllers\StockInController;
use App\Http\Controllers\StockTakeController;
use App\Livewire\Admin\UserRoleManager;
use Illuminate\Support\Facades\Route;

// §7.9: DashboardController itself returns the guest "welcome" view when not logged in.
Route::get('/', [DashboardController::class, 'index']);

// §7.2 public routes — SEC-AU-04: rate limited, no `auth` middleware (that's the point).
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/login', SsoLoginController::class)->name('login');
    Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
});

Route::get('/logout', SsoLogoutController::class)->name('logout');

// §7.2 GET /verify/{ulid} — public, no login; only doc_no/date/status, never personal data.
Route::get('/verify/{ulid}', [DocumentVerifyController::class, 'show'])->name('verify.show');

// FR-RQ-07: the advisor's emailed alternative to logging in — a 72-hour Laravel signed
// URL is the only authorization this needs (no `auth` middleware, deliberately public).
Route::middleware('signed')->group(function () {
    Route::get('/approve/{requisition}', [RequisitionApprovalController::class, 'showSigned'])
        ->name('requisitions.approve.signed');
    Route::post('/approve/{requisition}', [RequisitionApprovalController::class, 'decideSigned']);
});

// SEC-PD-02: outside the `account.` prefix so its route names match the plain
// `privacy-notice.*` names `EnsurePrivacyConsent`/`SsoCallbackController` both check.
Route::middleware('auth')->prefix('privacy-notice')->name('privacy-notice.')->group(function () {
    Route::get('/', [PrivacyNoticeController::class, 'show'])->name('show');
    Route::post('/', [PrivacyNoticeController::class, 'accept'])->name('accept');
});

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/pending-role', PendingRoleController::class)->name('pending-role');
    Route::get('/complete-profile', [CompleteProfileController::class, 'show'])->name('complete-profile');
    Route::post('/complete-profile', [CompleteProfileController::class, 'update'])->name('complete-profile.update');
    Route::get('/my-data', [AccountDataController::class, 'show'])->name('my-data');
    Route::get('/my-data/export', [AccountDataController::class, 'export'])->name('my-data.export');
});

// FR-AU-07 — SEC-AZ-01: `can:` gates on top of `auth` (UserPolicy::viewAny requires user.manage).
Route::middleware(['auth', 'can:viewAny,App\Models\User'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', UserRoleManager::class)->name('users.index');
});

// Small admin CRUD so goods receiving (T-022) has a real lab_id to file GRNs under —
// authorization enforced per-action inside LabController/LabPolicy (lab.manage).
Route::middleware('auth')->prefix('admin/labs')->name('admin.labs.')->group(function () {
    Route::get('/', \App\Livewire\Admin\LabTable::class)->name('index');
    Route::get('/create', [LabController::class, 'create'])->name('create');
    Route::post('/', [LabController::class, 'store'])->name('store');
    Route::get('/{lab}/edit', [LabController::class, 'edit'])->name('edit');
    Route::put('/{lab}', [LabController::class, 'update'])->name('update');
});

// FR-MD-01/07 — authorization also enforced per-action inside ItemController/ItemTable.
Route::middleware('auth')->prefix('items')->name('items.')->group(function () {
    Route::get('/', \App\Livewire\Items\ItemTable::class)->name('index');
    Route::get('/create', [ItemController::class, 'create'])->name('create');
    Route::post('/', [ItemController::class, 'store'])->name('store');
    Route::get('/{item}', [ItemController::class, 'show'])->name('show');
    Route::get('/{item}/edit', [ItemController::class, 'edit'])->name('edit');
    Route::put('/{item}', [ItemController::class, 'update'])->name('update');
    Route::post('/{item}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/{item}/ledger', \App\Livewire\Items\ItemLedger::class)->name('ledger');
    Route::get('/{item}/ledger/export/pdf', [LedgerExportController::class, 'pdf'])->name('ledger.export.pdf');
    Route::get('/{item}/ledger/export/excel', [LedgerExportController::class, 'excel'])->name('ledger.export.excel');
});

// SEC-AZ-06: no direct/public file URL — every download is authorized per-request.
Route::middleware('auth')->get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
    ->name('attachments.download');

// NFR-02: status/download page for a queued F-03 export — the notification's own link
// target, addressed by its own ULID (not nested under /items, since it outlives the
// item's own ledger page and its ownership check is independent of item.view).
Route::middleware('auth')->get('/ledger-exports/{ledgerExportRequest}', [LedgerExportController::class, 'showExport'])
    ->name('ledger-exports.show');

// FR-MD-04/05 — location tree + BR-10 incompatibility warning.
Route::middleware('auth')->prefix('locations')->name('locations.')->group(function () {
    Route::get('/', \App\Livewire\Locations\LocationTree::class)->name('index');
    Route::get('/create', [LocationController::class, 'create'])->name('create');
    Route::post('/', [LocationController::class, 'store'])->name('store');
    Route::get('/{location}/edit', [LocationController::class, 'edit'])->name('edit');
    Route::put('/{location}', [LocationController::class, 'update'])->name('update');
});

// FR-RC-01..06 — authorization enforced per-action inside the controllers/GoodsReceiptPolicy.
Route::middleware('auth')->prefix('goods-receipts')->name('goods-receipts.')->group(function () {
    Route::get('/', \App\Livewire\GoodsReceipts\GoodsReceiptTable::class)->name('index');
    Route::get('/create', [GoodsReceiptController::class, 'create'])->name('create');
    Route::post('/', [GoodsReceiptController::class, 'store'])->name('store');
    Route::get('/{goods_receipt}', [GoodsReceiptController::class, 'show'])->name('show');
    Route::put('/{goods_receipt}', [GoodsReceiptController::class, 'update'])->name('update');
    Route::post('/{goods_receipt}/items', [GoodsReceiptItemController::class, 'store'])->name('items.store');
    Route::delete('/{goods_receipt}/items/{goods_receipt_item}', [GoodsReceiptItemController::class, 'destroy'])->name('items.destroy');
    Route::post('/{goods_receipt}/confirm', [GoodsReceiptController::class, 'confirm'])->name('confirm');
    Route::post('/{goods_receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('cancel');
    Route::get('/{goods_receipt}/labels/{size}', [GoodsReceiptController::class, 'labels'])->name('labels');
});

// Working Stock: เติมสต็อก/รับเข้าคลังย่อย และพิมพ์สติกเกอร์บาร์โค้ด
Route::middleware('auth')->prefix('stock-in')->name('stock-in.')->group(function () {
    Route::get('/', [StockInController::class, 'create'])->name('create');
    Route::post('/', [StockInController::class, 'store'])->name('store');
    Route::get('/labels/{size}', [StockInController::class, 'labels'])->name('labels');
});

// FR-RQ-01..05 — authorization enforced per-action inside the controllers/RequisitionPolicy.
Route::middleware('auth')->prefix('requisitions')->name('requisitions.')->group(function () {
    Route::get('/', \App\Livewire\Requisitions\RequisitionTable::class)->name('index');
    Route::get('/create', [RequisitionController::class, 'create'])->name('create');
    Route::post('/', [RequisitionController::class, 'store'])->name('store');
    Route::get('/items/{item}/balance', [RequisitionController::class, 'itemBalance'])->name('items.balance');
    Route::get('/{requisition}', [RequisitionController::class, 'show'])->name('show');
    Route::get('/{requisition}/pdf', [RequisitionController::class, 'pdf'])->name('pdf');
    Route::put('/{requisition}', [RequisitionController::class, 'update'])->name('update');
    Route::post('/{requisition}/items', [RequisitionItemController::class, 'store'])->name('items.store');
    Route::delete('/{requisition}/items/{requisition_item}', [RequisitionItemController::class, 'destroy'])->name('items.destroy');
    Route::post('/{requisition}/submit', [RequisitionController::class, 'submit'])->name('submit');
    Route::post('/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('cancel');
    Route::post('/{requisition}/advisor-decide', [RequisitionApprovalController::class, 'decide'])->name('advisor-decide');
    Route::post('/{requisition}/scientist-decide', [RequisitionApprovalController::class, 'scientistDecide'])->name('scientist-decide');
    Route::get('/{requisition}/issue', [RequisitionIssueController::class, 'create'])->name('issue.create');
    Route::post('/{requisition}/items/{requisition_item}/issue', [RequisitionIssueController::class, 'store'])->name('items.issue');
    Route::post('/{requisition}/items/{requisition_item}/return', [RequisitionReturnController::class, 'store'])->name('items.return');
    // FR-RQ-11 OTP fallback: rate limited so a scientist can't spam a receiver's inbox.
    Route::post('/{requisition}/receiver-otp', [RequisitionIssueController::class, 'sendReceiverOtp'])
        ->middleware('throttle:5,1')
        ->name('receiver-otp.send');
});

// FR-ST-02..04 — authorization enforced per-action inside the controller/StockTakePolicy.
Route::middleware('auth')->prefix('stock-takes')->name('stock-takes.')->group(function () {
    Route::get('/', \App\Livewire\StockTakes\StockTakeTable::class)->name('index');
    Route::get('/create', [StockTakeController::class, 'create'])->name('create');
    Route::post('/', [StockTakeController::class, 'store'])->name('store');
    Route::get('/{stock_take}', [StockTakeController::class, 'show'])->name('show');
    Route::get('/{stock_take}/scan', [StockTakeController::class, 'scan'])->name('scan');
    Route::post('/{stock_take}/count', [StockTakeController::class, 'recordCount'])->name('count');
    Route::post('/{stock_take}/submit', [StockTakeController::class, 'submit'])->name('submit');
    Route::post('/{stock_take}/approve', [StockTakeController::class, 'approve'])->name('approve');
    Route::post('/{stock_take}/cancel', [StockTakeController::class, 'cancel'])->name('cancel');
});

// FR-ST-05 — authorization enforced per-action inside the controller/DisposalPolicy.
Route::middleware('auth')->prefix('disposals')->name('disposals.')->group(function () {
    Route::get('/', \App\Livewire\Disposals\DisposalTable::class)->name('index');
    Route::get('/create', [DisposalController::class, 'create'])->name('create');
    Route::post('/', [DisposalController::class, 'store'])->name('store');
    Route::get('/{disposal}', [DisposalController::class, 'show'])->name('show');
    Route::post('/{disposal}/approve', [DisposalController::class, 'approve'])->name('approve');
    Route::post('/{disposal}/reject', [DisposalController::class, 'reject'])->name('reject');
});

// FR-LG-07 — a single-step create-and-approve form; authorization gated on ledger.adjust (LAB_MANAGER).
Route::middleware('auth')->prefix('adjustments')->name('adjustments.')->group(function () {
    Route::get('/', [AdjustmentController::class, 'index'])->name('index');
    Route::get('/create', [AdjustmentController::class, 'create'])->name('create');
    Route::post('/', [AdjustmentController::class, 'store'])->name('store');
});

// FR-NT-01..06 — the in-app half of every notification; every user sees only their own.
Route::middleware('auth')->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
    Route::post('/{notification}/read', [NotificationController::class, 'read'])->name('read');
});

// FR-8 / §7.8 — every report not already served by F-01/F-03; gated on report.view.
Route::middleware('auth')->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/usage-summary/excel', [ReportController::class, 'usageSummaryExcel'])->name('usage-summary.excel');
    Route::get('/expiring-stock/excel', [ReportController::class, 'expiringStockExcel'])->name('expiring-stock.excel');
    Route::get('/below-reorder-point/excel', [ReportController::class, 'belowReorderPointExcel'])->name('below-reorder-point.excel');
    Route::get('/dead-stock/excel', [ReportController::class, 'deadStockExcel'])->name('dead-stock.excel');
    Route::get('/controlled-substances/excel', [ReportController::class, 'controlledSubstancesExcel'])->name('controlled-substances.excel');
    Route::get('/controlled-substances/pdf', [ReportController::class, 'controlledSubstancesPdf'])->name('controlled-substances.pdf');
    Route::get('/stock-takes/{stock_take}/excel', [ReportController::class, 'stockTakeVarianceExcel'])->name('stock-take.excel');
    Route::get('/stock-takes/{stock_take}/pdf', [ReportController::class, 'stockTakeVariancePdf'])->name('stock-take.pdf');
});

// T-053 (ZAP baseline scan, AC #10): a truly unmatched path never enters the 'web'
// group's middleware at all (there's no route to attach it to), so ForceHttps/
// SecurityHeaders never ran on it — ZAP flagged the resulting 404 as missing CSP
// (Medium). A fallback route IS part of this file's automatic 'web' group, so its
// 404 gets the same headers as every real page.
Route::fallback(fn () => abort(404));
