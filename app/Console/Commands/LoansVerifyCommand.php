<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Loan\Services\LoanLedgerHasher;
use App\Domain\Notification\Services\NotificationService;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 §6: `php artisan loans:verify`, the lending module's twin of `ledger:verify`. Checks
 *   (a) the hash chain of every asset,
 *   (b) that each asset's total / on-loan / unavailable equal its latest movement's "after" values,
 *   (d) that no ADJUST_IN / ADJUST_OUT / REPLACE_IN / RETIRE row lacks a different approver (the DB CHECK refuses
 *       new ones; this catches anything older or written around it).
 * Check (c), on-loan against the open loan documents, comes with the loan documents in a later step.
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

        LoanAsset::query()->orderBy('id')->chunkById(200, function ($assets) use ($hasher, &$problems, &$checked) {
            foreach ($assets as $asset) {
                $checked++;
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
