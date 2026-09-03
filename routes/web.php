<?php

use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Auth\PendingRoleController;
use App\Http\Controllers\Auth\SsoCallbackController;
use App\Http\Controllers\Auth\SsoLoginController;
use App\Http\Controllers\Auth\SsoLogoutController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\GoodsReceiptItemController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LabController;
use App\Http\Controllers\LedgerExportController;
use App\Http\Controllers\LocationController;
use App\Livewire\Admin\UserRoleManager;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // No real dashboard yet (spec §7.9 / T-046, Phase 4) — a quick-links home in the
    // meantime so a logged-in user isn't stranded on a bare login/logout card.
    return auth()->check() ? view('home') : view('welcome');
});

// §7.2 public routes — SEC-AU-04: rate limited, no `auth` middleware (that's the point).
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/login', SsoLoginController::class)->name('login');
    Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
});

Route::get('/logout', SsoLogoutController::class)->name('logout');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/pending-role', PendingRoleController::class)->name('pending-role');
    Route::get('/complete-profile', [CompleteProfileController::class, 'show'])->name('complete-profile');
    Route::post('/complete-profile', [CompleteProfileController::class, 'update'])->name('complete-profile.update');
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
