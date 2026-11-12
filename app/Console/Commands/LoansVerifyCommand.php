<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Loan\Services\LoanLedgerHasher;
use App\Domain\Notification\Services\NotificationService;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 §6: `php artisan loans:verify`, the lending module's twin of `ledger:verify`. Checks
 *   (a) the hash chain of every asset,
 *   (b) that each asset's total / on-loan / unavailable equal its latest movement's "after" values,
 *   (c) that each asset's on-loan quantity equals what the open loan documents (ON_LOAN / PARTIALLY_RETURNED) still
 *       have out on their lines (step 3), and that each asset's unavailable quantity equals the quantities of its
 *       incidents that are still PENDING (step 4: damaged pieces waiting for repair, lost pieces waiting for a result),
 *   (d) that no ADJUST_IN / ADJUST_OUT / REPLACE_IN / RETIRE row lacks a different approver (the DB CHECK refuses
 *       new ones; this catches anything older or written around it).
 *
 * `--notify` emails every ADMIN when something is wrong (the scheduler runs it that way, hourly), like
 * the stock ledger's FR-NT-06. Exit code 1 when a problem is found.
 */
final class LoansVerifyCommand extends Command
{
    protected $signature = 'loans:verify {--notify : Email every ADMIN when a problem is found}';

    protected $description = 'ตรวจสอบความสมบูรณ์ของบัญชีของให้ยืม (hash chain, ยอดคงเหลือ, ผู้อนุมัติ) และแจ้ง ADMIN เมื่อพบความผิดปกติ';

    public function handle(LoanLedgerHasher $hasher, NotificationService $notifications): int
    {
        // Before the tables exist (deploy not finished) there is nothing to check: stay quiet, do not error.
        if (! Schema::hasTable('loan_assets') || ! Schema::hasTable('loan_movements')) {
            $this->info((string) __('loans.verify_cmd.no_tables'));

            return self::SUCCESS;
        }

        $problems = [];
        $checked = 0;

        // (c) what the open loan documents say is out, per asset: handed-out quantity minus everything that came back
        // or was written down as lost, over the lines of loans that are ON_LOAN / PARTIALLY_RETURNED. Static SQL, no input.
        $outByAsset = [];
        if (Schema::hasTable('loans') && Schema::hasTable('loan_lines')) {
            $outByAsset = DB::table('loan_lines')
                ->join('loans', 'loans.id', '=', 'loan_lines.loan_id')
                ->whereIn('loans.status', Loan::OUT)
                ->groupBy('loan_lines.loan_asset_id')
                ->selectRaw('loan_lines.loan_asset_id AS asset_id, SUM(COALESCE(loan_lines.qty_approved, loan_lines.qty) - loan_lines.qty_returned_ok - loan_lines.qty_returned_damaged - loan_lines.qty_lost) AS out_qty')
                ->pluck('out_qty', 'asset_id')
                ->all();
        }

        // (c), second half (step 4): what the PENDING incidents say is unusable, per asset. Nothing else ever puts a
        // piece into `qty_unavailable` (only RETURN_DAMAGED / MARK_LOST do, and only the incident's result takes it out).
        $checkUnavailable = Schema::hasTable('loan_incidents') && Schema::hasTable('loan_lines');
        $unavailableByAsset = [];
        if ($checkUnavailable) {
            $unavailableByAsset = DB::table('loan_incidents')
                ->join('loan_lines', 'loan_lines.id', '=', 'loan_incidents.loan_line_id')
                ->where('loan_incidents.resolution', 'PENDING')
                ->groupBy('loan_lines.loan_asset_id')
                ->selectRaw('loan_lines.loan_asset_id AS asset_id, SUM(loan_incidents.qty) AS unavailable_qty')
                ->pluck('unavailable_qty', 'asset_id')
                ->all();
        }

        LoanAsset::query()->orderBy('id')->chunkById(200, function ($assets) use ($hasher, $outByAsset, $unavailableByAsset, $checkUnavailable, &$problems, &$checked) {
            foreach ($assets as $asset) {
                $checked++;

                $expectedOut = (string) ($outByAsset[$asset->id] ?? '0');
                if (! is_numeric($expectedOut) || bccomp($asset->qty_on_loan, $expectedOut, 6) !== 0) {
                    $problems[] = "asset #{$asset->id}: on-loan quantity differs from the open loan documents";
                }

                if ($checkUnavailable) {
                    $expectedUnavailable = (string) ($unavailableByAsset[$asset->id] ?? '0');
                    if (! is_numeric($expectedUnavailable) || bccomp($asset->qty_unavailable, $expectedUnavailable, 6) !== 0) {
                        $problems[] = "asset #{$asset->id}: unavailable quantity differs from the pending incidents";
                    }
                }

                $chain = $hasher->verifyChain($asset->id);
                if (! $chain['ok']) {
                    $problems[] = "asset #{$asset->id}: chain broken at movement id={$chain['broken_at']}";
                }

                $last = LoanMovement::where('loan_asset_id', $asset->id)->orderByDesc('id')->first();
                if ($last === null) {
                    $problems[] = "asset #{$asset->id}: no movement at all";

                    continue;
                }
                if (
                    bccomp($asset->qty_total, $last->qty_total_after, 6) !== 0
                    || bccomp($asset->qty_on_loan, $last->qty_on_loan_after, 6) !== 0
                    || bccomp($asset->qty_unavailable, $last->qty_unavailable_after, 6) !== 0
                ) {
                    $problems[] = "asset #{$asset->id}: quantities differ from the latest movement (id={$last->id})";
                }
            }
        });

        $unapproved = LoanMovement::query()
            ->whereIn('movement_type', ['ADJUST_IN', 'ADJUST_OUT', 'REPLACE_IN', 'RETIRE'])
            ->where(fn ($q) => $q->whereNull('approved_by')->orWhereColumn('approved_by', 'created_by'))
            ->orderBy('id')
            ->pluck('id');
        foreach ($unapproved as $movementId) {
            $problems[] = "movement id={$movementId}: approval missing or same person as the creator";
        }

        if ($problems === []) {
            $this->info((string) __('loans.verify_cmd.ok', ['count' => $checked]));

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error((string) __('loans.verify_cmd.broken', ['problem' => $problem]));
        }

        if ($this->option('notify')) {
            $summary = implode('; ', array_slice($problems, 0, 20)).(count($problems) > 20 ? ' …' : '');
            foreach ($notifications->usersWithRole('ADMIN') as $admin) {
                $notifications->emailOnly(
                    $admin,
                    (string) __('loans.verify_notify_title'),
                    (string) __('loans.verify_notify_body', ['problems' => $summary]),
                    null,
                );
            }
            $this->error((string) __('loans.verify_cmd.notified'));
        }

        return self::FAILURE;
    }
}
