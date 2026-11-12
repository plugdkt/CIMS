<?php

declare(strict_types=1);

namespace App\Domain\Loan\Reports;

use App\Domain\Loan\Services\LoanDailyNotifier;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ITM-2610-051 step 5 (spec section 14): the reports of the lending module. Read-only; Query Builder only (every
 * value from a request is a binding; the few selectRaw / whereRaw fragments are fixed text).
 *
 * Every query is narrowed by LoanReportScope::labFilter() — there is no report method without it — so a warehouse
 * manager reads their own branch, one without a branch reads nothing, and ADMIN reads every branch.
 *
 * What a report never shows:
 *  - a price / value, unless the viewer holds `loan.asset_manage` (LoanReportScope::canSeeValue);
 *  - free text that may describe a person (an incident's description / result note, a loan's remark, a loan's
 *    purpose_detail);
 *  - in the incident report: the cases of a loan the viewer borrowed or typed in themselves (the same rule as the
 *    incident pages, LoanIncidentPolicy::view); in the movement and monthly audit reports, the people, reference and
 *    letter of such a case's movements (hidesIncident(), that row only).
 *
 * "Overdue" is the module's one definition (Loan::overdue()): still out, something still with the borrower, due
 * date before today.
 */
final class LoanReportService
{
    public const OUTSTANDING = 'outstanding';

    public const AVAILABILITY = 'availability';

    public const MOVEMENTS = 'movements';

    public const BORROWER_SUMMARY = 'borrower_summary';

    public const INCIDENTS = 'incidents';

    public const MONTHLY_AUDIT = 'monthly_audit';

    public const SELF_ACTIONS = 'self_actions';

    public const KPI = 'kpi';

    /** Every report and the filters its page offers (the branch filter is offered to ADMIN on all of them). */
    public const FILTERS = [
        self::OUTSTANDING => ['borrower', 'item', 'min_days'],
        self::AVAILABILITY => ['item'],
        self::MOVEMENTS => ['item', 'from', 'to'],
        self::BORROWER_SUMMARY => ['from', 'to'],
        self::INCIDENTS => ['from', 'to', 'kind'],
        self::MONTHLY_AUDIT => ['month'],
        self::SELF_ACTIONS => ['month'],
        self::KPI => ['from', 'to'],
    ];

    /** An `external_ref` shorter than this is flagged in the monthly audit report (the forms demand at least 5). */
    private const MIN_REF_LENGTH = 5;

    /** The movements by which a piece leaves the borrower's hands (the KPI "returned late" is timed by the last of them). */
    private const BACK_MOVEMENTS = ['RETURN_OK', 'RETURN_DAMAGED', 'MARK_LOST'];

    private const OUTSTANDING_SQL = 'COALESCE(ll.qty_approved, ll.qty) - ll.qty_returned_ok - ll.qty_returned_damaged - ll.qty_lost';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FILTERS);
    }

    /**
     * @param  int  $limit  at most this many rows (one more is read to know whether something was cut off)
     */
    public function build(string $key, LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        $limit = max(1, $limit);

        return match ($key) {
            self::OUTSTANDING => $this->outstanding($scope, $filter, $limit),
            self::AVAILABILITY => $this->availability($scope, $filter, $limit),
            self::MOVEMENTS => $this->movements($scope, $filter, $limit),
            self::BORROWER_SUMMARY => $this->borrowerSummary($scope, $filter, $limit),
            self::INCIDENTS => $this->incidents($scope, $filter, $limit),
            self::MONTHLY_AUDIT => $this->monthlyAudit($scope, $filter, $limit),
            self::SELF_ACTIONS => $this->selfActions($scope, $filter, $limit),
            self::KPI => $this->kpi($scope, $filter),
            default => throw new InvalidArgumentException('unknown loan report'),
        };
    }

    /** "Who has what, and what is late": one row per line that still has pieces with the borrower. */
    private function outstanding(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        $today = CarbonImmutable::today();

        $query = DB::table('loan_lines as ll')
            ->join('loans as l', 'l.id', '=', 'll.loan_id')
            ->join('labs as b', 'b.id', '=', 'l.lab_id')
            ->join('users as u', 'u.id', '=', 'l.borrower_id')
            ->join('loan_assets as a', 'a.id', '=', 'll.loan_asset_id')
            ->join('items as i', 'i.id', '=', 'a.item_id')
            ->whereIn('l.status', Loan::OUT)
            ->whereRaw(self::OUTSTANDING_SQL.' > 0');
        $this->scopeLab($query, $scope, 'l.lab_id');

        if ($filter->borrower !== null) {
            $like = $this->like($filter->borrower);
            $query->where(fn (Builder $w) => $w->where('u.full_name', 'like', $like)->orWhere('u.username', 'like', $like));
        }
        if ($filter->item !== null) {
            $this->whereItem($query, $filter->item);
        }
        if ($filter->minDays !== null && $filter->minDays > 0) {
            // Overdue by at least that many days.
            $query->whereDate('l.due_date', '<=', $today->subDays($filter->minDays)->toDateString());
        }

        $rows = $query
            ->orderBy('l.due_date')->orderBy('l.id')->orderBy('ll.line_no')
            ->limit($limit + 1)
            ->selectRaw('l.doc_no, b.code AS lab_code, u.full_name, i.item_code, i.name_th, a.asset_tag, l.handed_out_at, l.due_date, '.self::OUTSTANDING_SQL.' AS out_qty')
            ->get();

        $out = [];
        foreach ($rows->take($limit) as $row) {
            $due = CarbonImmutable::parse((string) $row->due_date)->startOfDay();
            $out[] = [
                (string) $row->doc_no,
                (string) $row->lab_code,
                (string) $row->full_name,
                (string) $row->item_code,
                (string) $row->name_th,
                (string) $row->asset_tag,
                $this->qty((string) $row->out_qty),
                $this->date($row->handed_out_at),
                $due->format('d/m/Y'),
                $due->lt($today) ? (string) (int) $due->diffInDays($today) : '0',
            ];
        }

        return new LoanReportTable(self::OUTSTANDING, $this->headings([
            'doc_no', 'lab', 'borrower', 'item_code', 'item', 'asset_tag', 'outstanding', 'handed_out', 'due', 'days_overdue',
        ]), $out, $rows->count() > $limit);
    }

    /** What is free, out and unusable, per registered asset. The replacement value only for `loan.asset_manage`. */
    private function availability(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        $query = DB::table('loan_assets as a')
            ->join('labs as b', 'b.id', '=', 'a.lab_id')
            ->join('items as i', 'i.id', '=', 'a.item_id');
        $this->scopeLab($query, $scope, 'a.lab_id');
        if ($filter->item !== null) {
            $this->whereItem($query, $filter->item);
        }

        $rows = $query
            ->orderBy('b.code')->orderBy('i.name_th')->orderBy('a.id')
            ->limit($limit + 1)
            ->selectRaw('b.code AS lab_code, i.item_code, i.name_th, a.asset_tag, a.tracking_mode, a.qty_total, a.qty_on_loan, a.qty_unavailable, a.qty_total - a.qty_on_loan - a.qty_unavailable AS qty_free, a.status, a.replacement_value')
            ->get();

        $out = [];
        foreach ($rows->take($limit) as $row) {
            $cells = [
                (string) $row->lab_code,
                (string) $row->item_code,
                (string) $row->name_th,
                (string) $row->asset_tag,
                (string) __('loans.mode_'.strtolower((string) $row->tracking_mode)),
                $this->qty((string) $row->qty_total),
                $this->qty((string) $row->qty_on_loan),
                $this->qty((string) $row->qty_unavailable),
                $this->qty((string) $row->qty_free),
                (string) __('loans.status_'.strtolower((string) $row->status)),
            ];
            if ($scope->canSeeValue) {
                $cells[] = $row->replacement_value === null ? '' : (string) $row->replacement_value;
            }
            $out[] = $cells;
        }

        $columns = ['lab', 'item_code', 'item', 'asset_tag', 'mode', 'total', 'on_loan', 'unavailable', 'available', 'status'];
        if ($scope->canSeeValue) {
            $columns[] = 'replacement_value';
        }

        return new LoanReportTable(self::AVAILABILITY, $this->headings($columns), $out, $rows->count() > $limit);
    }

    /** The movement log (the lending ledger), newest first. */
    private function movements(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        $query = $this->movementBase($scope);
        if ($filter->item !== null) {
            $this->whereItem($query, $filter->item);
        }
        $this->whereDateRange($query, 'm.created_at', $filter);

        $rows = $query
            ->orderByDesc('m.id')
            ->limit($limit + 1)
            ->selectRaw('m.created_at, b.code AS lab_code, i.item_code, i.name_th, a.asset_tag, m.movement_type, m.qty, m.qty_total_after, m.qty_on_loan_after, m.qty_unavailable_after, l.doc_no, c.full_name AS creator_name, p.full_name AS approver_name, m.external_ref, m.incident_id, l.borrower_id AS loan_borrower_id, l.created_by AS loan_created_by')
            ->get();

        $out = [];
        foreach ($rows->take($limit) as $row) {
            $hide = $this->hidesIncident($scope, $row);
            $out[] = [
                $this->dateTime($row->created_at),
                (string) $row->lab_code,
                (string) $row->item_code,
                (string) $row->name_th,
                (string) $row->asset_tag,
                (string) __('loans.movement_'.strtolower((string) $row->movement_type)),
                $this->qty((string) $row->qty),
                $this->qty((string) $row->qty_total_after),
                $this->qty((string) $row->qty_on_loan_after),
                $this->qty((string) $row->qty_unavailable_after),
                (string) $row->doc_no,
                $hide ? $this->hiddenCell() : (string) $row->creator_name,
                $hide ? $this->hiddenCell() : (string) $row->approver_name,
                $hide ? $this->hiddenCell() : (string) $row->external_ref,
            ];
        }

        return new LoanReportTable(self::MOVEMENTS, $this->headings([
            'when', 'lab', 'item_code', 'item', 'asset_tag', 'movement', 'qty', 'total_after', 'on_loan_after', 'unavailable_after', 'doc_no', 'by', 'approver', 'external_ref',
        ]), $out, $rows->count() > $limit);
    }

    /**
     * Loans that were handed out, counted per borrower and purpose (teaching / research / other). The period is the
     * pick-up date. QA fix (ITM-2610-051): `purpose_detail` is free text the borrower typed (it may name people), so
     * it is neither a column nor a grouping here; the document page still shows it to those who may open the loan.
     */
    private function borrowerSummary(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        $pieces = DB::table('loan_lines')
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM(COALESCE(qty_approved, qty)) AS pieces');

        $query = DB::table('loans as l')
            ->join('labs as b', 'b.id', '=', 'l.lab_id')
            ->join('users as u', 'u.id', '=', 'l.borrower_id')
            ->leftJoinSub($pieces, 'x', 'x.loan_id', '=', 'l.id')
            ->whereNotNull('l.handed_out_at');
        $this->scopeLab($query, $scope, 'l.lab_id');
        $this->whereDateRange($query, 'l.loan_date', $filter);

        $rows = $query
            ->groupBy('b.code', 'l.borrower_id', 'u.full_name', 'l.borrower_status', 'l.purpose_type')
            ->orderBy('b.code')->orderBy('u.full_name')->orderBy('l.purpose_type')
            ->limit($limit + 1)
            ->selectRaw('b.code AS lab_code, u.full_name, l.borrower_status, l.purpose_type, COUNT(*) AS docs, COALESCE(SUM(x.pieces), 0) AS pieces')
            ->get();

        $out = [];
        foreach ($rows->take($limit) as $row) {
            $out[] = [
                (string) $row->lab_code,
                (string) $row->full_name,
                (string) __('loans.report.borrower_status_'.strtolower((string) $row->borrower_status)),
                (string) __('loans.purpose_'.strtolower((string) $row->purpose_type)),
                (string) (int) $row->docs,
                $this->qty((string) $row->pieces),
            ];
        }

        return new LoanReportTable(self::BORROWER_SUMMARY, $this->headings([
            'lab', 'borrower', 'borrower_status', 'purpose', 'docs', 'pieces',
        ]), $out, $rows->count() > $limit);
    }

    /**
     * Damage / loss cases and the recorded result. Never a case of a loan the viewer borrowed or typed in (they may
     * not open it either). No description, no result note; the value only for `loan.asset_manage`.
     */
    private function incidents(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        $files = DB::table('attachments')
            ->where('owner_type', 'LoanIncident')
            ->groupBy('owner_id')
            ->selectRaw('owner_id, COUNT(*) AS files');

        $query = DB::table('loan_incidents as n')
            ->join('loans as l', 'l.id', '=', 'n.loan_id')
            ->join('labs as b', 'b.id', '=', 'l.lab_id')
            ->join('loan_lines as ll', 'll.id', '=', 'n.loan_line_id')
            ->join('loan_assets as a', 'a.id', '=', 'll.loan_asset_id')
            ->join('items as i', 'i.id', '=', 'a.item_id')
            ->join('users as r', 'r.id', '=', 'n.recorded_by')
            ->leftJoin('users as s', 's.id', '=', 'n.resolved_by')
            ->leftJoinSub($files, 'f', 'f.owner_id', '=', 'n.id')
            ->where('l.borrower_id', '!=', $scope->viewerId)
            ->where('l.created_by', '!=', $scope->viewerId);
        $this->scopeLab($query, $scope, 'l.lab_id');
        $this->whereDateRange($query, 'n.created_at', $filter);
        if ($filter->kind !== null) {
            $query->where('n.kind', $filter->kind);
        }

        $rows = $query
            ->orderByDesc('n.id')
            ->limit($limit + 1)
            ->selectRaw('n.created_at, l.doc_no, b.code AS lab_code, i.item_code, i.name_th, a.asset_tag, n.kind, n.qty, n.resolution, n.disposition, n.external_ref, n.resolved_at, r.full_name AS recorder_name, s.full_name AS resolver_name, COALESCE(f.files, 0) AS files, n.value_snapshot')
            ->get();

        $out = [];
        foreach ($rows->take($limit) as $row) {
            $cells = [
                $this->date($row->created_at),
                (string) $row->doc_no,
                (string) $row->lab_code,
                (string) $row->item_code,
                (string) $row->name_th,
                (string) $row->asset_tag,
                (string) __('loans.incident_kind_'.strtolower((string) $row->kind)),
                $this->qty((string) $row->qty),
                (string) __('loans.incident_resolution_'.strtolower((string) $row->resolution)),
                $row->disposition === null ? '' : (string) __('loans.report.disposition_'.strtolower((string) $row->disposition)),
                (string) $row->external_ref,
                $this->date($row->resolved_at),
                (string) $row->recorder_name,
                (string) $row->resolver_name,
                (string) (int) $row->files,
            ];
            if ($scope->canSeeValue) {
                $cells[] = $row->value_snapshot === null ? '' : (string) $row->value_snapshot;
            }
            $out[] = $cells;
        }

        $columns = ['recorded_at', 'doc_no', 'lab', 'item_code', 'item', 'asset_tag', 'kind', 'qty', 'result', 'disposition', 'external_ref', 'resolved_at', 'recorder', 'resolver', 'files'];
        if ($scope->canSeeValue) {
            $columns[] = 'value';
        }

        return new LoanReportTable(self::INCIDENTS, $this->headings($columns), $out, $rows->count() > $limit);
    }

    /**
     * The monthly report for checking the books: every WRITE_OFF, ADJUST_OUT, ADJUST_IN and REPLACE_IN of the month —
     * what, how many, who recorded, who approved, the outside reference, whether a file is attached — with a remark
     * when the reference is missing, unusually short, or used by another case as well.
     */
    private function monthlyAudit(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        [$start, $end] = $this->monthRange($filter);

        $files = DB::table('attachments')
            ->where('owner_type', 'LoanIncident')
            ->where('doc_type', 'LOAN_DOC')
            ->groupBy('owner_id')
            ->selectRaw('owner_id, COUNT(*) AS files');

        $rows = $this->movementBase($scope)
            ->leftJoinSub($files, 'f', 'f.owner_id', '=', 'm.incident_id')
            ->whereIn('m.movement_type', LoanDailyNotifier::AUDIT_MOVEMENTS)
            ->where('m.created_at', '>=', $start)
            ->where('m.created_at', '<', $end)
            ->orderBy('m.id')
            ->limit($limit + 1)
            ->selectRaw('m.id, m.created_at, b.code AS lab_code, i.item_code, i.name_th, a.asset_tag, m.movement_type, m.qty, l.doc_no, c.full_name AS creator_name, p.full_name AS approver_name, m.external_ref, m.incident_id, COALESCE(f.files, 0) AS files, l.borrower_id AS loan_borrower_id, l.created_by AS loan_created_by')
            ->get();

        $duplicates = $this->duplicateRefs($scope, array_values($rows->pluck('external_ref')->filter()->map(fn ($ref) => trim((string) $ref))->unique()->all()));

        $out = [];
        foreach ($rows->take($limit) as $row) {
            if ($this->hidesIncident($scope, $row)) {
                // A case of the viewer's own loan: what, when and how many stay; who decided, the reference, the
                // letter and the remarks about the reference do not (the incident page is closed to them too).
                $out[] = [
                    $this->dateTime($row->created_at),
                    (string) $row->lab_code,
                    (string) $row->item_code,
                    (string) $row->name_th,
                    (string) $row->asset_tag,
                    (string) __('loans.movement_'.strtolower((string) $row->movement_type)),
                    $this->qty((string) $row->qty),
                    (string) $row->doc_no,
                    $this->hiddenCell(),
                    $this->hiddenCell(),
                    $this->hiddenCell(),
                    $this->hiddenCell(),
                    $this->hiddenCell(),
                ];

                continue;
            }

            $ref = trim((string) $row->external_ref);
            $remarks = [];
            if ($ref === '') {
                $remarks[] = (string) __('loans.report.ref_missing');
            } elseif (mb_strlen($ref) < self::MIN_REF_LENGTH) {
                $remarks[] = (string) __('loans.report.ref_short');
            }
            if ($ref !== '' && isset($duplicates[mb_strtolower($ref)])) {
                $remarks[] = (string) __('loans.report.ref_duplicate');
            }

            $out[] = [
                $this->dateTime($row->created_at),
                (string) $row->lab_code,
                (string) $row->item_code,
                (string) $row->name_th,
                (string) $row->asset_tag,
                (string) __('loans.movement_'.strtolower((string) $row->movement_type)),
                $this->qty((string) $row->qty),
                (string) $row->doc_no,
                (string) $row->creator_name,
                (string) $row->approver_name,
                $ref,
                // Only a movement that belongs to an incident can carry a file (the outside letter).
                $row->incident_id === null ? (string) __('loans.report.file_na') : (string) __((int) $row->files > 0 ? 'loans.report.file_yes' : 'loans.report.file_no'),
                implode(' / ', $remarks),
            ];
        }

        return new LoanReportTable(self::MONTHLY_AUDIT, $this->headings([
            'when', 'lab', 'item_code', 'item', 'asset_tag', 'movement', 'qty', 'doc_no', 'by', 'approver', 'external_ref', 'file', 'remark',
        ]), $out, $rows->count() > $limit);
    }

    /**
     * The other half of the monthly check: what somebody did on their OWN loan (approved it, handed it out, took it
     * back, decided its renewal). Allowed, and therefore listed: the audit rows `LOAN_SELF_*` of the month.
     */
    private function selfActions(LoanReportScope $scope, LoanReportFilter $filter, int $limit): LoanReportTable
    {
        [$start, $end] = $this->monthRange($filter);

        $query = DB::table('audit_logs as g')
            ->join('loans as l', 'l.id', '=', 'g.entity_id')
            ->join('labs as b', 'b.id', '=', 'l.lab_id')
            ->leftJoin('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.entity_type', Loan::class)
            // `_` is a wildcard in LIKE: escaped, so only actions that really start with LOAN_SELF_ match.
            ->where('g.action', 'like', 'LOAN\_SELF\_%')
            ->where('g.occurred_at', '>=', $start)
            ->where('g.occurred_at', '<', $end);
        $this->scopeLab($query, $scope, 'l.lab_id');

        $rows = $query
            ->orderBy('g.id')
            ->limit($limit + 1)
            ->selectRaw('g.occurred_at, b.code AS lab_code, l.doc_no, g.action, u.full_name')
            ->get();

        $out = [];
        foreach ($rows->take($limit) as $row) {
            $action = (string) $row->action;
            $labelKey = 'loans.report.self_'.strtolower(substr($action, strlen('LOAN_SELF_')));
            $out[] = [
                $this->dateTime($row->occurred_at),
                (string) $row->lab_code,
                (string) $row->doc_no,
                trans()->has($labelKey) ? (string) __($labelKey) : $action,
                $action,
                (string) $row->full_name,
            ];
        }

        return new LoanReportTable(self::SELF_ACTIONS, $this->headings([
            'when', 'lab', 'doc_no', 'self_action', 'action_code', 'by',
        ]), $out, $rows->count() > $limit);
    }

    /**
     * R2R "after" figures over loans handed out in the period: how long from request to hand-out, how long things
     * stay out, how many came back late or are late now, and how many are "status unknown" (overdue for more than
     * `unknown_after_days` days with nothing recorded on the loan in that time).
     */
    private function kpi(LoanReportScope $scope, LoanReportFilter $filter): LoanReportTable
    {
        $base = fn (): Builder => tap(DB::table('loans as l')->whereNotNull('l.handed_out_at'), function (Builder $q) use ($scope, $filter) {
            $this->scopeLab($q, $scope, 'l.lab_id');
            $this->whereDateRange($q, 'l.handed_out_at', $filter);
        });

        $handedOut = (int) $base()->count();
        $leadMinutes = $base()->whereNotNull('l.submitted_at')->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, l.submitted_at, l.handed_out_at)) AS v')->value('v');
        $closed = (int) $base()->where('l.status', 'CLOSED')->whereNotNull('l.closed_at')->count();
        $cycleMinutes = $base()->where('l.status', 'CLOSED')->whereNotNull('l.closed_at')->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, l.handed_out_at, l.closed_at)) AS v')->value('v');
        // "Returned late" = the LAST piece came back (RETURN_OK / RETURN_DAMAGED / MARK_LOST) after the due date — not
        // `closed_at`, which is when the last incident RESULT was recorded (a pending case is not the borrower's delay,
        // same rule as Loan::overdue()). "The last one after the due date" = "any one after the due date".
        $closedLate = (int) $base()->where('l.status', 'CLOSED')->whereNotNull('l.closed_at')
            ->whereExists(fn (Builder $x) => $x->selectRaw('1')->from('loan_movements as m')
                ->whereColumn('m.loan_id', 'l.id')
                ->whereIn('m.movement_type', self::BACK_MOVEMENTS)
                ->whereRaw('DATE(m.created_at) > l.due_date'))
            ->count();

        $today = CarbonImmutable::today();
        $overdueNow = fn (int $moreThanDays): Builder => $base()
            ->whereIn('l.status', Loan::OUT)
            ->whereDate('l.due_date', '<', $today->subDays($moreThanDays)->toDateString())
            ->whereExists(fn (Builder $x) => $x->selectRaw('1')->from('loan_lines as ll')->whereColumn('ll.loan_id', 'l.id')->whereRaw(self::OUTSTANDING_SQL.' > 0'));

        $lateNow = (int) $overdueNow(0)->count();

        $silentDays = max(1, (int) config('loans.unknown_after_days', 30));
        $since = $today->subDays($silentDays);
        $unknown = (int) $overdueNow($silentDays)
            ->whereNotExists(fn (Builder $x) => $x->selectRaw('1')->from('loan_movements as m')->whereColumn('m.loan_id', 'l.id')->where('m.created_at', '>=', $since))
            ->whereNotExists(fn (Builder $x) => $x->selectRaw('1')->from('loan_due_changes as d')->whereColumn('d.loan_id', 'l.id')->where('d.created_at', '>=', $since))
            ->count();

        $late = $closedLate + $lateNow;

        $rows = [
            [(string) __('loans.report.kpi_handed_out'), (string) $handedOut],
            [(string) __('loans.report.kpi_lead_hours'), $leadMinutes === null ? '' : $this->decimal((string) $leadMinutes, '60')],
            [(string) __('loans.report.kpi_closed'), (string) $closed],
            [(string) __('loans.report.kpi_cycle_days'), $cycleMinutes === null ? '' : $this->decimal((string) $cycleMinutes, '1440')],
            [(string) __('loans.report.kpi_late'), (string) $late],
            [(string) __('loans.report.kpi_late_rate'), $handedOut === 0 ? '' : bcdiv(bcmul((string) $late, '100', 4), (string) $handedOut, 1)],
            [(string) __('loans.report.kpi_unknown', ['days' => $silentDays]), (string) $unknown],
        ];

        return new LoanReportTable(self::KPI, $this->headings(['metric', 'value']), $rows);
    }

    /**
     * The month asked for (the current month when none was given).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [first day 00:00, first day of the next month 00:00)
     */
    private function monthRange(LoanReportFilter $filter): array
    {
        $start = $filter->month === null ? null : CarbonImmutable::createFromFormat('!Y-m', $filter->month);
        if (! $start instanceof CarbonImmutable) {
            $start = CarbonImmutable::today()->startOfMonth();
        }

        return [$start, $start->addMonth()];
    }

    /**
     * Which of these references are used by more than one case — over the whole ledger the viewer may read, not only
     * the month. A WRITE_OFF and the REPLACE_IN of the SAME incident share one reference on purpose, so movements of
     * one incident count once; a movement without an incident (an adjustment) counts by itself.
     *
     * @param  list<string>  $refs
     * @return array<string, true> keyed by the lower-cased reference
     */
    private function duplicateRefs(LoanReportScope $scope, array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        $query = DB::table('loan_movements as m')->whereIn('m.external_ref', $refs);
        $this->scopeLab($query, $scope, 'm.lab_id');

        /** @var array<string, array<string, true>> $cases */
        $cases = [];
        foreach ($query->get(['m.id', 'm.incident_id', 'm.external_ref']) as $row) {
            $ref = mb_strtolower(trim((string) $row->external_ref));
            $cases[$ref][$row->incident_id === null ? 'm'.$row->id : 'i'.$row->incident_id] = true;
        }

        $duplicates = [];
        foreach ($cases as $ref => $group) {
            if (count($group) > 1) {
                $duplicates[$ref] = true;
            }
        }

        return $duplicates;
    }

    /** Movements joined to what every movement report shows (asset, item, branch, document, the two people). */
    private function movementBase(LoanReportScope $scope): Builder
    {
        $query = DB::table('loan_movements as m')
            ->join('loan_assets as a', 'a.id', '=', 'm.loan_asset_id')
            ->join('items as i', 'i.id', '=', 'a.item_id')
            ->join('labs as b', 'b.id', '=', 'm.lab_id')
            ->join('users as c', 'c.id', '=', 'm.created_by')
            ->leftJoin('users as p', 'p.id', '=', 'm.approved_by')
            ->leftJoin('loans as l', 'l.id', '=', 'm.loan_id');
        $this->scopeLab($query, $scope, 'm.lab_id');

        return $query;
    }

    /**
     * QA fix (ITM-2610-051): a movement of a damage / loss case on a loan the VIEWER borrowed or typed in. Its people,
     * reference and letter are hidden in that row only, the same rule as the incident pages and the incident report
     * (LoanAccess::canSeeIncidentFiles / LoanIncidentPolicy::view): they may not open the case, so a report must not
     * tell them who decided it either. Needs `incident_id`, `loan_borrower_id`, `loan_created_by` on the row.
     */
    private function hidesIncident(LoanReportScope $scope, object $row): bool
    {
        if (! isset($row->incident_id)) {
            return false;
        }

        return (isset($row->loan_borrower_id) && (int) $row->loan_borrower_id === $scope->viewerId)
            || (isset($row->loan_created_by) && (int) $row->loan_created_by === $scope->viewerId);
    }

    private function hiddenCell(): string
    {
        return (string) __('loans.report.hidden_own_case');
    }

    /** The branch rule, on every query: see LoanReportScope::labFilter(). */
    private function scopeLab(Builder $query, LoanReportScope $scope, string $column): void
    {
        $labId = $scope->labFilter();
        if ($labId !== null) {
            $query->where($column, $labId);
        }
    }

    /** Item name / code or the asset's own tag (needs the aliases `i` and `a`). */
    private function whereItem(Builder $query, string $term): void
    {
        $like = $this->like($term);
        $query->where(fn (Builder $w) => $w
            ->where('i.name_th', 'like', $like)
            ->orWhere('i.name_en', 'like', $like)
            ->orWhere('i.item_code', 'like', $like)
            ->orWhere('a.asset_tag', 'like', $like));
    }

    private function whereDateRange(Builder $query, string $column, LoanReportFilter $filter): void
    {
        if ($filter->from !== null) {
            $query->whereDate($column, '>=', $filter->from);
        }
        if ($filter->to !== null) {
            $query->whereDate($column, '<=', $filter->to);
        }
    }

    /** `%` and `_` typed in a search box are literal characters, not wildcards. */
    private function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function headings(array $columns): array
    {
        return array_map(fn (string $column) => (string) __('loans.report.col_'.$column), $columns);
    }

    /** A whole-piece quantity without the six decimals ("3.000000" -> "3"); string in, string out (no float). */
    private function qty(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /**
     * An average of minutes turned into hours / days with one decimal (a duration, not a stock quantity).
     *
     * @param  numeric-string  $divisor
     */
    private function decimal(string $minutes, string $divisor): string
    {
        return is_numeric($minutes) ? bcdiv($minutes, $divisor, 1) : '';
    }

    private function date(mixed $value): string
    {
        return $value === null || $value === '' ? '' : CarbonImmutable::parse((string) $value)->format('d/m/Y');
    }

    private function dateTime(mixed $value): string
    {
        return $value === null || $value === '' ? '' : CarbonImmutable::parse((string) $value)->format('d/m/Y H:i');
    }
}
