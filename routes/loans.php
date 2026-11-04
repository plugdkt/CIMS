<?php

use App\Http\Controllers\Loan\LoanApprovalController;
use App\Http\Controllers\Loan\LoanAssetController;
use App\Http\Controllers\Loan\LoanAvailableController;
use App\Http\Controllers\Loan\LoanBranchController;
use App\Http\Controllers\Loan\LoanController;
use Illuminate\Support\Facades\Route;

/*
 | ITM-2610-051 (borrow / return module). Loaded by a single `require` line in routes/web.php, so these
 | routes sit inside the `web` middleware group like every other page. Every route is behind `auth`
 | (except the advisor's signed email link below); authorization is per action (Form Request + LoanAssetPolicy /
 | LoanPolicy), and URLs use ULIDs.
 |
 | Step 1: the register of lending assets and the "available to lend" page.
 | Step 2: request / approve / cancel (the loan document), the queue, the branch list, the "no branch yet" page.
 */

// FR-LN-12: the advisor's emailed alternative to logging in. The 72-hour signed URL is the credential (no `auth`);
// throttled because it is public (spec 13). LoanApprovalService decides whether that advisor may really act.
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('/loan-approve/{loan}', [LoanApprovalController::class, 'showSigned'])->name('loans.approve.signed');
    Route::post('/loan-approve/{loan}', [LoanApprovalController::class, 'decideSigned']);
});

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

    // Step 2. The fixed paths come before `/{loan}` so they are never taken for a loan's ULID.
    Route::get('/mine', [LoanController::class, 'mine'])->name('mine');
    Route::get('/create', [LoanController::class, 'create'])->name('create');
    Route::post('/', [LoanController::class, 'store'])->name('store');
    Route::get('/pending-lab', [LoanController::class, 'pendingLab'])->name('pending-lab');
    Route::get('/queue', [LoanApprovalController::class, 'queue'])->name('queue');
    Route::get('/branch', [LoanBranchController::class, 'index'])->name('branch');

    Route::get('/{loan}', [LoanController::class, 'show'])->name('show');
    Route::get('/{loan}/edit', [LoanController::class, 'edit'])->name('edit');
    Route::put('/{loan}', [LoanController::class, 'update'])->name('update');
    Route::post('/{loan}/submit', [LoanController::class, 'submit'])->name('submit');
    Route::post('/{loan}/cancel', [LoanController::class, 'cancel'])->name('cancel');
    Route::post('/{loan}/advisor-decide', [LoanApprovalController::class, 'advisorDecide'])->name('advisor-decide');
    Route::post('/{loan}/manager-decide', [LoanApprovalController::class, 'managerDecide'])->name('manager-decide');
});
