<?php

use App\Http\Controllers\Loan\LoanAssetController;
use App\Http\Controllers\Loan\LoanAvailableController;
use Illuminate\Support\Facades\Route;

/*
 | ITM-2610-051 (borrow / return module). Loaded by a single `require` line in routes/web.php, so these
 | routes sit inside the `web` middleware group like every other page. Every route is behind `auth`;
 | authorization is per action (Form Request + LoanAssetPolicy), and URLs use ULIDs.
 |
 | Step 1: the register of lending assets and the "available to lend" page.
 */
Route::middleware('auth')->prefix('loans')->name('loans.')->group(function () {
    Route::get('/available', [LoanAvailableController::class, 'index'])->name('available');

    Route::prefix('assets')->name('assets.')->group(function () {
        Route::get('/', [LoanAssetController::class, 'index'])->name('index');
        Route::get('/create', [LoanAssetController::class, 'create'])->name('create');
        Route::post('/', [LoanAssetController::class, 'store'])->name('store');
        Route::get('/{loan_asset}', [LoanAssetController::class, 'show'])->name('show');
        Route::put('/{loan_asset}', [LoanAssetController::class, 'update'])->name('update');
        Route::post('/{loan_asset}/adjust', [LoanAssetController::class, 'adjust'])->name('adjust');
        Route::post('/{loan_asset}/retire', [LoanAssetController::class, 'retire'])->name('retire');
        Route::post('/{loan_asset}/verify', [LoanAssetController::class, 'verify'])->name('verify');
    });
});
