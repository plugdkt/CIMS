<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Loan\Services\LoanAssetImportService;
use App\Models\Lab;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * ITM-2610-051 WP-5.4 (FR-LN-05): `php artisan loans:import-assets <file> --lab=CODE --by=USERNAME [--dry-run]`.
 * The first fill of one branch's register of lending assets from a CSV file (columns: LoanAssetImportService).
 *
 * Always run with --dry-run first: it checks every row against the database and writes nothing. Without it the file
 * is checked again and, only when nothing is wrong, every row is registered in one transaction (all or none), each
 * as an ordinary registration by --by (who must hold `loan.asset_manage` of that branch, or be ADMIN).
 */
final class LoansImportAssetsCommand extends Command
{
    protected $signature = 'loans:import-assets
        {path : CSV file (UTF-8, first line = column names)}
        {--lab= : labs.code of the branch}
        {--by= : username of the person the registrations are recorded under}
        {--dry-run : check only, write nothing}';

    protected $description = 'นำเข้าทะเบียนของให้ยืมของสาขาจากไฟล์ CSV (ตรวจด้วย --dry-run ก่อน; ทั้งไฟล์หรือไม่นำเข้าเลย)';

    public function handle(LoanAssetImportService $importer): int
    {
        /** @var string $path */
        $path = $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error((string) __('loans.import.file_unreadable'));

            return self::FAILURE;
        }

        $lab = Lab::where('code', (string) $this->option('lab'))->where('is_active', true)->first();
        if ($lab === null) {
            $this->error((string) __('loans.import.lab_not_found'));

            return self::FAILURE;
        }

        $actor = User::where('username', (string) $this->option('by'))->first();
        if ($actor === null) {
            $this->error((string) __('loans.import.actor_not_found'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $result = $importer->check($path, $lab, $actor);
            $this->report($result['errors']);
            if ($result['errors'] !== []) {
                return self::FAILURE;
            }
            $this->info((string) __('loans.import.dry_run_ok', ['count' => count($result['rows']), 'lab' => $lab->code]));

            return self::SUCCESS;
        }

        $result = $importer->import($path, $lab, $actor);
        $this->report($result['errors']);
        if ($result['errors'] !== []) {
            $this->error((string) __('loans.import.nothing_written'));

            return self::FAILURE;
        }
        $this->info((string) __('loans.import.done', ['count' => $result['imported'], 'lab' => $lab->code]));

        return self::SUCCESS;
    }

    /** @param  list<array{line: int, column: string, message: string}>  $errors */
    private function report(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        $this->table(
            [(string) __('loans.import.col_line'), (string) __('loans.import.col_column'), (string) __('loans.import.col_problem')],
            array_map(fn (array $e) => [$e['line'] === 0 ? '-' : (string) $e['line'], $e['column'], $e['message']], array_slice($errors, 0, 200)),
        );
        if (count($errors) > 200) {
            $this->line((string) __('loans.import.more_errors', ['count' => count($errors) - 200]));
        }
    }
}
