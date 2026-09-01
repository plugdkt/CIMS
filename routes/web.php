<?php

use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Auth\PendingRoleController;
use App\Http\Controllers\Auth\SsoCallbackController;
use App\Http\Controllers\Auth\SsoLoginController;
use App\Http\Controllers\Auth\SsoLogoutController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ItemController;
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

// FR-MD-01/07 — authorization also enforced per-action inside ItemController/ItemTable.
Route::middleware('auth')->prefix('items')->name('items.')->group(function () {
    Route::get('/', \App\Livewire\Items\ItemTable::class)->name('index');
    Route::get('/create', [ItemController::class, 'create'])->name('create');
    Route::post('/', [ItemController::class, 'store'])->name('store');
    Route::get('/{item}', [ItemController::class, 'show'])->name('show');
    Route::get('/{item}/edit', [ItemController::class, 'edit'])->name('edit');
    Route::put('/{item}', [ItemController::class, 'update'])->name('update');
    Route::post('/{item}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
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
