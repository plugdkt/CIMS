<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Lab;
use App\Models\LoanAsset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 WP-5.4 (FR-LN-05, "Could"): load the register of lending assets of ONE branch from a CSV file — the
 * first fill of a room's register — with a dry run first, like `catalog:import`. Run by `loans:import-assets`.
 *
 * Every row becomes one ordinary registration through LoanLedgerService::register() (REGISTER movement, hash chain,
 * audit `LOAN_ASSET_REGISTER`), so nothing here writes a quantity or a movement itself. The whole file is checked
 * first; a file with ANY problem writes nothing, and a clean file is written in ONE transaction (all rows or none).
 *
 * The file: UTF-8 (a BOM is fine), first line = column names. Columns (only the first three are required):
 *   item_code, tracking_mode (UNIT | POOL), qty (POOL: whole number; UNIT: empty or 1), asset_tag (REQUIRED on a
 *   UNIT row and not allowed on a POOL row — the import never generates a tag, so a file run twice is refused),
 *   location_code (a location of that branch), max_loan_days (1-365), replacement_value (0-99999999.99),
 *   condition_note (up to 255 characters).
 */
final class LoanAssetImportService
{
    public const COLUMNS = ['item_code', 'tracking_mode', 'qty', 'asset_tag', 'location_code', 'max_loan_days', 'replacement_value', 'condition_note'];

    private const REQUIRED = ['item_code', 'tracking_mode', 'qty'];

    public const MAX_ROWS = 2000;

    public function __construct(private readonly LoanLedgerService $ledger)
    {
    }

    /**
     * Read and check the file against the database, writing nothing.
     *
     * @return array{rows: list<array{line: int, data: array<string, mixed>}>, errors: list<array{line: int, column: string, message: string}>}
     */
    public function check(string $path, Lab $lab, User $actor): array
    {
        $errors = [];
        $rows = [];

        if (! $this->mayManage($actor, $lab)) {
            return ['rows' => [], 'errors' => [$this->error(0, '-', 'actor_not_allowed')]];
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['rows' => [], 'errors' => [$this->error(0, '-', 'file_unreadable')]];
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($header) || $header === [null]) {
                return ['rows' => [], 'errors' => [$this->error(1, '-', 'header_missing')]];
            }
            $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header);

            foreach ($header as $name) {
                if (! in_array($name, self::COLUMNS, true)) {
                    $errors[] = $this->error(1, $name, 'column_unknown');
                }
            }
            foreach (self::REQUIRED as $name) {
                if (! in_array($name, $header, true)) {
                    $errors[] = $this->error(1, $name, 'column_missing');
                }
            }
            if (count($header) !== count(array_unique($header))) {
                $errors[] = $this->error(1, '-', 'column_twice');
            }
            if ($errors !== []) {
                return ['rows' => [], 'errors' => $errors];
            }

            /** @var array<string, int> $tagsInFile */
            $tagsInFile = [];
            /** @var array<int, int> $poolsInFile item id => first line */
            $poolsInFile = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                    continue; // a blank line
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $errors[] = $this->error($line, '-', 'too_many_rows', ['max' => self::MAX_ROWS]);
                    break;
                }
                if (count($cells) !== count($header)) {
                    $errors[] = $this->error($line, '-', 'cell_count');

                    continue;
                }

                /** @var array<string, string> $raw */
                $raw = [];
                foreach ($header as $i => $name) {
                    $raw[$name] = trim((string) $cells[$i]);
                }

                [$data, $rowErrors] = $this->checkRow($line, $raw, $lab, $tagsInFile, $poolsInFile);
                array_push($errors, ...$rowErrors);
                if ($rowErrors === []) {
                    $rows[] = ['line' => $line, 'data' => $data];
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === [] && $errors === []) {
            $errors[] = $this->error(0, '-', 'no_rows');
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Check, then register every row in one transaction. Refuses (writes nothing) when the check found anything.
     *
     * @return array{imported: int, errors: list<array{line: int, column: string, message: string}>}
     */
    public function import(string $path, Lab $lab, User $actor): array
    {
        $checked = $this->check($path, $lab, $actor);
        if ($checked['errors'] !== []) {
            return ['imported' => 0, 'errors' => $checked['errors']];
        }

        $line = 0;
        try {
            $count = DB::transaction(function () use ($checked, $lab, $actor, $path, &$line) {
                $count = 0;
                foreach ($checked['rows'] as $row) {
                    $line = $row['line'];
                    /** @var array{lab_id: int, item_id: int, tracking_mode: string, qty: string|int, asset_tag?: string|null, location_id?: int|null, max_loan_days?: int|null, replacement_value?: string|null, condition_note?: string|null} $data */
                    $data = $row['data'];
                    $this->ledger->register($actor, $data);
                    $count++;
                }

                // One row for the whole file on top of the per-asset LOAN_ASSET_REGISTER rows (no file name, no content).
                AuditLog::record(
                    action: 'LOAN_ASSET_IMPORT',
                    userId: $actor->id,
                    username: $actor->username,
                    entityType: Lab::class,
                    entityId: $lab->id,
                    newValue: ['lab_id' => $lab->id, 'rows' => $count, 'sha256' => (string) hash_file('sha256', $path)],
                );

                return $count;
            });
        } catch (LoanDomainException $e) {
            // Something changed between the check and the write (e.g. a tag registered meanwhile): nothing was kept.
            return ['imported' => 0, 'errors' => [['line' => $line, 'column' => '-', 'message' => $e->getMessage()]]];
        }

        return ['imported' => $count, 'errors' => []];
    }

    /** The same gate as LoanLedgerService::register(): an active holder of `loan.asset_manage` of that branch, or ADMIN. */
    public function mayManage(User $actor, Lab $lab): bool
    {
        return $actor->is_active
            && $actor->hasPermission('loan.asset_manage')
            && ($actor->hasRole('ADMIN') || ($actor->lab_id !== null && (int) $actor->lab_id === (int) $lab->id));
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, int>  $tagsInFile
     * @param  array<int, int>  $poolsInFile
     * @return array{0: array<string, mixed>, 1: list<array{line: int, column: string, message: string}>}
     */
    private function checkRow(int $line, array $raw, Lab $lab, array &$tagsInFile, array &$poolsInFile): array
    {
        $errors = [];
        $get = fn (string $name): string => $raw[$name] ?? '';

        // Item: by its code, active, counted in whole pieces (BR-L01).
        $item = $get('item_code') === '' ? null : Item::with('baseUnit')->where('item_code', strtoupper($get('item_code')))->first();
        if ($item === null || ! $item->is_active) {
            $errors[] = $this->error($line, 'item_code', 'item_not_found');
        } elseif ($item->baseUnit === null || $item->baseUnit->dimension !== 'COUNT') {
            $errors[] = $this->error($line, 'item_code', 'item_not_countable');
        }

        $mode = strtoupper($get('tracking_mode'));
        if (! in_array($mode, ['UNIT', 'POOL'], true)) {
            $errors[] = $this->error($line, 'tracking_mode', 'mode_invalid');
        }

        $qty = $get('qty');
        if ($mode === 'UNIT') {
            if ($qty !== '' && $qty !== '1') {
                $errors[] = $this->error($line, 'qty', 'unit_qty_one');
            }
            $qty = '1';
        } elseif (preg_match('/^[0-9]{1,6}$/', $qty) !== 1 || (int) $qty < 1) {
            $errors[] = $this->error($line, 'qty', 'qty_whole');
        }

        $tag = $get('asset_tag');
        if ($tag === '' && $mode === 'UNIT') {
            // QA fix (ITM-2610-051): a UNIT row must name its tag. A generated tag would be new on every run, so the
            // same file run twice would register every untagged piece twice and nothing could notice. (The web form
            // still generates one when left empty: that is one registration by a person, not a re-runnable file.)
            $errors[] = $this->error($line, 'asset_tag', 'tag_required');
        } elseif ($tag !== '') {
            if ($mode !== 'UNIT') {
                $errors[] = $this->error($line, 'asset_tag', 'tag_unit_only');
            } elseif (mb_strlen($tag) > 64) {
                $errors[] = $this->error($line, 'asset_tag', 'too_long', ['max' => 64]);
            } elseif (isset($tagsInFile[mb_strtolower($tag)])) {
                $errors[] = $this->error($line, 'asset_tag', 'tag_twice_in_file', ['other' => $tagsInFile[mb_strtolower($tag)]]);
            } elseif (LoanAsset::where('asset_tag', $tag)->exists()) {
                $errors[] = $this->error($line, 'asset_tag', 'tag_exists');
            } else {
                $tagsInFile[mb_strtolower($tag)] = $line;
            }
        }

        // POOL: one active pool per branch and item, in the database and in the file.
        if ($mode === 'POOL' && $item !== null) {
            if (isset($poolsInFile[$item->id])) {
                $errors[] = $this->error($line, 'item_code', 'pool_twice_in_file', ['other' => $poolsInFile[$item->id]]);
            } elseif (LoanAsset::where('lab_id', $lab->id)->where('item_id', $item->id)->where('tracking_mode', 'POOL')->where('status', 'ACTIVE')->exists()) {
                $errors[] = $this->error($line, 'item_code', 'pool_exists');
            } else {
                $poolsInFile[$item->id] = $line;
            }
        }

        $locationId = null;
        if ($get('location_code') !== '') {
            $location = Location::where('code', $get('location_code'))->first();
            if ($location === null || (int) $location->lab_id !== (int) $lab->id) {
                $errors[] = $this->error($line, 'location_code', 'location_not_in_lab');
            } else {
                $locationId = (int) $location->id;
            }
        }

        $days = $get('max_loan_days');
        if ($days !== '' && (preg_match('/^[0-9]{1,3}$/', $days) !== 1 || (int) $days < 1 || (int) $days > 365)) {
            $errors[] = $this->error($line, 'max_loan_days', 'days_invalid');
        }

        // Money as text (no float): up to 8 digits and 2 decimals.
        $value = $get('replacement_value');
        if ($value !== '' && preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/', $value) !== 1) {
            $errors[] = $this->error($line, 'replacement_value', 'value_invalid');
        }

        $note = $get('condition_note');
        if (mb_strlen($note) > 255) {
            $errors[] = $this->error($line, 'condition_note', 'too_long', ['max' => 255]);
        }

        $data = [
            'lab_id' => (int) $lab->id,
            'item_id' => $item === null ? 0 : (int) $item->id,
            'tracking_mode' => $mode,
            'qty' => $qty,
            'asset_tag' => $tag === '' ? null : $tag,
            'location_id' => $locationId,
            'max_loan_days' => $days === '' ? null : (int) $days,
            'replacement_value' => $value === '' ? null : $value,
            'condition_note' => $note === '' ? null : $note,
        ];

        return [$data, $errors];
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array{line: int, column: string, message: string}
     */
    private function error(int $line, string $column, string $key, array $params = []): array
    {
        return ['line' => $line, 'column' => $column, 'message' => (string) __('loans.import.'.$key, $params)];
    }
}
