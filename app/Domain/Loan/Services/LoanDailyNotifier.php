<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Models\Loan;
use App\Models\LoanIncident;
use App\Models\LoanMovement;
use App\Models\LoanNoticeLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ITM-2610-051 step 5: the scheduled notices of the lending module (spec section 10, N4-N7) and the monthly audit
 * summary (spec section 14). Run by `loans:notify-daily` (07:00) and `loans:notify-monthly`.
 *
 * NFR-LN-05 (never twice on one day): every notice is CLAIMED first — one INSERT IGNORE into `loan_notice_log`,
 * whose UNIQUE key is (day, kind, person, subject) — and is sent only when that insert really added the row. A
 * second run on the same day, or two runs at the same moment, therefore send nothing again: the database decides.
 * The price is "at most once": if the mail server fails right after the claim, that one notice is not retried that
 * day (the failure is logged; the in-system notice is written before the email is tried).
 *
 * Who is told (the same channel rule as every branch notice of this module):
 *  - the borrower gets the notices about their OWN loan (not picked up, due soon, overdue);
 *  - the branch gets ONE summary per kind and day: in the system to the scientists and warehouse managers of the
 *    branch (LoanAccess::isHandlerFor), by email to the warehouse managers only, and only where the spec lists email.
 * What is said: document numbers and dates only — no name, no reason, no value, no item.
 */
final class LoanDailyNotifier
{
    public const TYPE_PICKUP = 'loan.pickup_reminder';

    public const TYPE_PICKUP_DIGEST = 'loan.pickup_digest';

    public const TYPE_DUE_SOON = 'loan.due_soon';

    public const TYPE_OVERDUE = 'loan.overdue';

    public const TYPE_OVERDUE_DIGEST = 'loan.overdue_digest';

    public const TYPE_INCIDENT_DIGEST = 'loan.incident_digest';

    public const TYPE_MONTHLY_AUDIT = 'loan.monthly_audit';

    /** The movement kinds the monthly audit report lists (spec section 14). */
    public const AUDIT_MOVEMENTS = ['WRITE_OFF', 'ADJUST_OUT', 'ADJUST_IN', 'REPLACE_IN'];

    /** At most this many document numbers are written out in one summary; the rest is a count. */
    private const DIGEST_DOCS = 10;

    /** `loan_notice_log` rows older than this are pruned (they only matter on their own day / month). */
    private const KEEP_DAYS = 400;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LoanAccess $access,
    ) {
    }

    /**
     * Everything of one day. Each part is isolated: a failure in one is logged and the others still run.
     *
     * @return array<string, int> how many notices were really sent now, per kind (0 everywhere on a repeated run)
     */
    public function runDaily(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $sent = [
            self::TYPE_PICKUP => 0,
            self::TYPE_PICKUP_DIGEST => 0,
            self::TYPE_DUE_SOON => 0,
            self::TYPE_OVERDUE => 0,
            self::TYPE_OVERDUE_DIGEST => 0,
            self::TYPE_INCIDENT_DIGEST => 0,
        ];

        $parts = [
            fn () => $this->notPickedUp($today),
            fn () => $this->dueSoon($today),
            fn () => $this->overdue($today),
            fn () => $this->pendingIncidents($today),
        ];

        foreach ($parts as $part) {
            try {
                foreach ($part() as $type => $count) {
                    $sent[$type] = ($sent[$type] ?? 0) + $count;
                }
            } catch (Throwable $e) {
                Log::error("loans:notify-daily: a part failed: {$e->getMessage()}");
            }
        }

        try {
            LoanNoticeLog::query()->where('notice_date', '<', $today->subDays(self::KEEP_DAYS)->toDateString())->delete();
        } catch (Throwable $e) {
            Log::warning("loans:notify-daily: pruning loan_notice_log failed: {$e->getMessage()}");
        }

        return $sent;
    }

    /**
     * N4: approved, but not picked up more than `pickup_reminder_days` days after the pick-up date (or after the
     * approval, when that came later). Told on the first day past that, then every 7th day — to the borrower and, as
     * one summary, to the counter of the branch. In the system only (spec). Nothing is cancelled automatically.
     *
     * @return array<string, int>
     */
    private function notPickedUp(CarbonImmutable $today): array
    {
        $grace = max(0, (int) config('loans.pickup_reminder_days', 3));
        $sent = [self::TYPE_PICKUP => 0, self::TYPE_PICKUP_DIGEST => 0];

        $loans = Loan::query()
            ->where('status', 'APPROVED')
            ->whereDate('loan_date', '<', $today->subDays($grace)->toDateString())
            ->orderBy('id')
            ->get();

        /** @var array<int, list<string>> $byLab */
        $byLab = [];
        foreach ($loans as $loan) {
            $from = CarbonImmutable::parse($loan->loan_date->toDateString());
            if ($loan->approved_at !== null) {
                $approvedDay = CarbonImmutable::parse($loan->approved_at->toDateString());
                $from = $approvedDay->gt($from) ? $approvedDay : $from;
            }
            $late = (int) $from->diffInDays($today) - $grace;
            if ($late < 1 || ! $this->isMilestone($late)) {
                continue;
            }

            $byLab[(int) $loan->lab_id][] = $loan->doc_no;

            $borrower = User::find($loan->borrower_id);
            if ($borrower !== null && $borrower->is_active && $this->claim($today, self::TYPE_PICKUP, $borrower->id, 'loan:'.$loan->id)) {
                $this->send(false, $borrower, self::TYPE_PICKUP, 'pickup', ['doc_no' => $loan->doc_no, 'date' => $loan->loan_date->format('d/m/Y')], route('loans.show', $loan));
                $sent[self::TYPE_PICKUP]++;
            }
        }

        foreach ($byLab as $labId => $docs) {
            foreach ($this->handlersOf($labId) as $handler) {
                if ($this->claim($today, self::TYPE_PICKUP_DIGEST, $handler->id, 'lab:'.$labId)) {
                    $this->send(false, $handler, self::TYPE_PICKUP_DIGEST, 'pickup_digest', $this->digestParams($docs, $today), route('loans.desk'));
                    $sent[self::TYPE_PICKUP_DIGEST]++;
                }
            }
        }

        return $sent;
    }

    /**
     * N5: things are still with the borrower and the due date is today or in `due_reminder_days` days (2) -> the
     * borrower, in the system and by email.
     *
     * @return array<string, int>
     */
    private function dueSoon(CarbonImmutable $today): array
    {
        $ahead = max(1, (int) config('loans.due_reminder_days', 2));
        $sent = [self::TYPE_DUE_SOON => 0];

        $loans = Loan::query()
            ->with('lines')
            ->whereIn('status', Loan::OUT)
            ->where(fn ($q) => $q->whereDate('due_date', $today->toDateString())->orWhereDate('due_date', $today->addDays($ahead)->toDateString()))
            ->orderBy('id')
            ->get();

        foreach ($loans as $loan) {
            if (! $loan->hasOutstanding()) {
                continue;
            }
            $borrower = User::find($loan->borrower_id);
            if ($borrower === null || ! $borrower->is_active || ! $this->claim($today, self::TYPE_DUE_SOON, $borrower->id, 'loan:'.$loan->id)) {
                continue;
            }

            $dueToday = $loan->due_date->toDateString() === $today->toDateString();
            $this->send(true, $borrower, self::TYPE_DUE_SOON, $dueToday ? 'due_today' : 'due_soon', ['doc_no' => $loan->doc_no, 'date' => $loan->due_date->format('d/m/Y')], route('loans.show', $loan));
            $sent[self::TYPE_DUE_SOON]++;
        }

        return $sent;
    }

    /**
     * N6: overdue (Loan::overdue(), the one definition) -> the borrower on the first day past the due date and then
     * every 7th day, in the system and by email. On a day when at least one loan of a branch reaches such a day, the
     * branch gets one summary of everything of the branch that is overdue: in the system to the counter (scientists
     * and warehouse managers), by email to the warehouse managers only.
     *
     * @return array<string, int>
     */
    private function overdue(CarbonImmutable $today): array
    {
        $sent = [self::TYPE_OVERDUE => 0, self::TYPE_OVERDUE_DIGEST => 0];

        $loans = Loan::query()->with('lines')->overdue()->orderBy('due_date')->orderBy('id')->get();

        /** @var array<int, list<string>> $byLab every overdue document of the branch */
        $byLab = [];
        /** @var array<int, bool> $labHasMilestone */
        $labHasMilestone = [];
        foreach ($loans as $loan) {
            $days = (int) CarbonImmutable::parse($loan->due_date->toDateString())->diffInDays($today);
            if ($days < 1) {
                continue;
            }
            $labId = (int) $loan->lab_id;
            $byLab[$labId][] = $loan->doc_no;

            if (! $this->isMilestone($days)) {
                continue;
            }
            $labHasMilestone[$labId] = true;

            $borrower = User::find($loan->borrower_id);
            if ($borrower !== null && $borrower->is_active && $this->claim($today, self::TYPE_OVERDUE, $borrower->id, 'loan:'.$loan->id)) {
                $this->send(true, $borrower, self::TYPE_OVERDUE, 'overdue', ['doc_no' => $loan->doc_no, 'date' => $loan->due_date->format('d/m/Y')], route('loans.show', $loan));
                $sent[self::TYPE_OVERDUE]++;
            }
        }

        foreach (array_keys($labHasMilestone) as $labId) {
            $params = $this->digestParams($byLab[$labId], $today);
            foreach ($this->handlersOf($labId) as $handler) {
                if (! $this->claim($today, self::TYPE_OVERDUE_DIGEST, $handler->id, 'lab:'.$labId)) {
                    continue;
                }
                $manager = $handler->isBranchManager();
                // The branch list needs `loan.view_all` (the managers); a scientist is sent to the counter's queue.
                $link = $handler->hasPermission('loan.view_all') ? route('loans.branch', ['overdue' => 1]) : route('loans.desk');
                $this->send($manager, $handler, self::TYPE_OVERDUE_DIGEST, 'overdue_digest', $params, $link);
                $sent[self::TYPE_OVERDUE_DIGEST]++;
            }
        }

        return $sent;
    }

    /**
     * N7: damage / loss cases still wait for a result -> every day, in the system only, to the people of the branch
     * who hold `loan.asset_approve` (the ones who may record a result). A person is never told about a case of a loan
     * they borrowed or typed in themselves (they cannot open it: LoanIncidentPolicy::view).
     *
     * @return array<string, int>
     */
    private function pendingIncidents(CarbonImmutable $today): array
    {
        $sent = [self::TYPE_INCIDENT_DIGEST => 0];

        $incidents = LoanIncident::query()
            ->where('resolution', 'PENDING')
            ->with('loan:id,doc_no,lab_id,borrower_id,created_by')
            ->orderBy('id')
            ->get();

        /** @var array<int, array<int, Loan>> $byLab */
        $byLab = [];
        foreach ($incidents as $incident) {
            $loan = $incident->loan;
            if ($loan !== null) {
                $byLab[(int) $loan->lab_id][$loan->id] = $loan;
            }
        }

        foreach ($byLab as $labId => $loans) {
            foreach ($this->branchUsers($labId) as $user) {
                if (! $user->hasPermission('loan.asset_approve')) {
                    continue;
                }
                $docs = [];
                foreach ($loans as $loan) {
                    if (! $this->access->isOwner($user, $loan)) {
                        $docs[] = $loan->doc_no;
                    }
                }
                if ($docs === [] || ! $this->claim($today, self::TYPE_INCIDENT_DIGEST, $user->id, 'lab:'.$labId)) {
                    continue;
                }
                $this->send(false, $user, self::TYPE_INCIDENT_DIGEST, 'incident_digest', $this->digestParams($docs, $today), route('loans.incidents.index'));
                $sent[self::TYPE_INCIDENT_DIGEST]++;
            }
        }

        return $sent;
    }

    /**
     * Spec section 14: at the start of a month, tell the people who check the books that the audit report of the month
     * that just closed is ready — every ADMIN (all branches) and every AUDITOR who has a branch (their own branch),
     * in the system and by email. The month and a count only. Once per person and month, whenever it is run.
     *
     * @param  string  $month  `YYYY-MM`
     * @return int how many were really sent now
     */
    public function runMonthly(string $month): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        if (! $start instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('month must be YYYY-MM');
        }
        $end = $start->addMonth();
        $sent = 0;

        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('code', ['ADMIN', 'AUDITOR']))
            ->with('roles.permissions')
            ->orderBy('id')
            ->get();

        foreach ($recipients as $user) {
            if (! $user->hasPermission('loan.report')) {
                continue;
            }
            $allBranches = $user->hasRole('ADMIN');
            if (! $allBranches && $user->lab_id === null) {
                continue;
            }

            try {
                $count = LoanMovement::query()
                    ->whereIn('movement_type', self::AUDIT_MOVEMENTS)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->when(! $allBranches, fn ($q) => $q->where('lab_id', $user->lab_id))
                    ->count();

                if (! $this->claim($start, self::TYPE_MONTHLY_AUDIT, $user->id, 'month:'.$month)) {
                    continue;
                }
                $this->send(true, $user, self::TYPE_MONTHLY_AUDIT, 'monthly_audit', ['month' => $start->format('m/Y'), 'count' => $count], route('loans.reports.show', ['report' => 'monthly_audit', 'month' => $month]));
                $sent++;
            } catch (Throwable $e) {
                Log::warning("loans:notify-monthly: user #{$user->id}: {$e->getMessage()}");
            }
        }

        return $sent;
    }

    /** Day 1 past the limit, then every 7th day after it (1, 8, 15, ...). */
    private function isMilestone(int $daysPast): bool
    {
        return $daysPast >= 1 && ($daysPast - 1) % 7 === 0;
    }

    /**
     * NFR-LN-05: true only for the ONE caller that gets to send this notice. INSERT IGNORE against the UNIQUE key
     * `uq_loan_notice`: 1 row added = ours; 0 = somebody (an earlier run, or a run going on right now) has it.
     */
    private function claim(CarbonImmutable $day, string $type, int $userId, string $subject): bool
    {
        return DB::table('loan_notice_log')->insertOrIgnore([
            'notice_date' => $day->toDateString(),
            'type' => $type,
            'user_id' => $userId,
            'subject' => $subject,
            'created_at' => now(),
        ]) === 1;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private function send(bool $withEmail, User $user, string $type, string $key, array $params, string $link): void
    {
        try {
            $title = (string) __("loans.daily.{$key}_title", $params);
            $body = (string) __("loans.daily.{$key}_body", $params);

            if ($withEmail) {
                $this->notifications->notifyInAppAndEmail($user, $type, $title, $body, $link);
            } else {
                $this->notifications->notifyInApp($user, $type, $title, $body, $link);
            }
        } catch (Throwable $e) {
            Log::warning("Scheduled loan notice {$type} to user #{$user->id} failed: {$e->getMessage()}");
        }
    }

    /**
     * @param  list<string>  $docs
     * @return array<string, string|int>
     */
    private function digestParams(array $docs, CarbonImmutable $today): array
    {
        $docs = array_values(array_unique($docs));
        $shown = array_slice($docs, 0, self::DIGEST_DOCS);
        $more = count($docs) - count($shown);

        return [
            'count' => count($docs),
            'docs' => implode(', ', $shown).($more > 0 ? ' '.__('loans.daily.and_more', ['count' => $more]) : ''),
            'date' => $today->format('d/m/Y'),
        ];
    }

    /**
     * The counter of a branch: its scientists and warehouse managers (never an ADMIN-only account).
     *
     * @return Collection<int, User>
     */
    private function handlersOf(int $labId): Collection
    {
        return $this->branchUsers($labId)->filter(fn (User $user) => $this->access->isHandlerFor($user, $labId))->values();
    }

    /** @return Collection<int, User> */
    private function branchUsers(int $labId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('lab_id', $labId)
            ->with('roles.permissions')
            ->orderBy('id')
            ->get();
    }
}
