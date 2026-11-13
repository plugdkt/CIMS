<?php

use App\Domain\Loan\Reports\LoanReportService;
use App\Http\Controllers\Loan\LoanApprovalController;
use App\Http\Controllers\Loan\LoanAssetController;
use App\Http\Controllers\Loan\LoanAvailableController;
use App\Http\Controllers\Loan\LoanBranchController;
use App\Http\Controllers\Loan\LoanCartController;
use App\Http\Controllers\Loan\LoanController;
use App\Http\Controllers\Loan\LoanCounterController;
use App\Http\Controllers\Loan\LoanIncidentController;
use App\Http\Controllers\Loan\LoanRenewalController;
use App\Http\Controllers\Loan\LoanReportController;
use Illuminate\Support\Facades\Route;

/*
 | ITM-2610-051 (borrow / return module). Loaded by a single `require` line in routes/web.php, so these
 | routes sit inside the `web` middleware group like every other page. Every route is behind `auth`
 | (except the advisor's signed email link below); authorization is per action (Form Request + LoanAssetPolicy /
 | LoanPolicy), and URLs use ULIDs.
 |
 | Step 1: the register of lending assets and the "available to lend" page.
 | Step 2: request / approve / cancel (the loan document), the queue, the branch list, the "no branch yet" page.
 | Step 3: the counter (queue of the branch, hand-out, return), renewals of the due date, the printable slip.
 | Step 4: damage / loss cases (recorded on the return page; listed, documented and resolved under /loans/incidents).
 | Step 5: the dashboard and the reports under /loans/reports (CSV export, audited).
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
    // ITM-2610-070: the borrowing cart (session only, borrowing for oneself). A line is addressed by the asset's ULID
    // as a plain string (not bound), so a line whose asset was retired since can still be changed or removed.
    Route::get('/cart', [LoanCartController::class, 'index'])->name('cart');
    Route::post('/cart', [LoanCartController::class, 'add'])->name('cart.add');
    Route::put('/cart/{asset}', [LoanCartController::class, 'update'])->where('asset', '[0-9A-Za-z]{26}')->name('cart.update');
    Route::delete('/cart/{asset}', [LoanCartController::class, 'remove'])->where('asset', '[0-9A-Za-z]{26}')->name('cart.remove');
    Route::get('/queue', [LoanApprovalController::class, 'queue'])->name('queue');
    Route::get('/branch', [LoanBranchController::class, 'index'])->name('branch');
    // Step 3 (FR-LN-17): the counter's queue of the user's own branch.
    Route::get('/desk', [LoanCounterController::class, 'desk'])->name('desk');

    // Step 4 (FR-LN-23/24): damage / loss cases, addressed by the incident's own ULID (LoanIncidentPolicy:
    // browseIncidents / view / attachFile / resolveIncident). A file is addressed under its incident, and one that
    // belongs to another owner answers 404. The borrower reaches none of these.
    Route::prefix('incidents')->name('incidents.')->group(function () {
        Route::get('/', [LoanIncidentController::class, 'index'])->name('index');
        Route::get('/{loan_incident}', [LoanIncidentController::class, 'show'])->name('show');
        Route::post('/{loan_incident}/resolve', [LoanIncidentController::class, 'resolve'])->name('resolve');
        Route::post('/{loan_incident}/files', [LoanIncidentController::class, 'storeFile'])->name('files.store');
        Route::get('/{loan_incident}/files/{attachment}', [LoanIncidentController::class, 'file'])->name('files.show');
    });

    // Step 5 (spec 14): the dashboard and the reports (LoanPolicy::browseReports + LoanReportRequest: own branch,
    // another branch = 403). `{report}` is one of a fixed list; every export is a CSV file and is audited.
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [LoanReportController::class, 'index'])->name('index');
        Route::get('/{report}', [LoanReportController::class, 'show'])->whereIn('report', LoanReportService::keys())->name('show');
        Route::get('/{report}/export', [LoanReportController::class, 'export'])->whereIn('report', LoanReportService::keys())->name('export');
    });

    Route::get('/{loan}', [LoanController::class, 'show'])->name('show');
    Route::get('/{loan}/edit', [LoanController::class, 'edit'])->name('edit');
    Route::put('/{loan}', [LoanController::class, 'update'])->name('update');
    Route::post('/{loan}/submit', [LoanController::class, 'submit'])->name('submit');
    Route::post('/{loan}/cancel', [LoanController::class, 'cancel'])->name('cancel');
    Route::post('/{loan}/advisor-decide', [LoanApprovalController::class, 'advisorDecide'])->name('advisor-decide');
    Route::post('/{loan}/manager-decide', [LoanApprovalController::class, 'managerDecide'])->name('manager-decide');

    // Step 3: hand-out / return (LoanPolicy::handOutLoan / receiveReturnLoan), renewals (renewLoan /
    // decideRenewalLoan; a request is addressed by the loan's ULID + its sequence number), the slip (printLoan).
    Route::get('/{loan}/pdf', [LoanController::class, 'pdf'])->name('pdf');
    Route::get('/{loan}/handout', [LoanCounterController::class, 'handoutForm'])->name('handout.form');
    Route::post('/{loan}/handout', [LoanCounterController::class, 'handout'])->name('handout');
    Route::get('/{loan}/return', [LoanCounterController::class, 'returnForm'])->name('return.form');
    Route::post('/{loan}/return', [LoanCounterController::class, 'receiveReturn'])->name('return');
    Route::post('/{loan}/renewals', [LoanRenewalController::class, 'store'])->name('renewals.store');
    Route::post('/{loan}/renewals/{seq}/decide', [LoanRenewalController::class, 'decide'])->whereNumber('seq')->name('renewals.decide');
});
