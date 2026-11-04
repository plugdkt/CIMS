<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * FR-NT-01..06: the three channel combinations spec's own table lists — "Email + In-app"
 * (FR-NT-01..04), "In-app" only (FR-NT-05), and "Email" only, routed to ADMIN (FR-NT-06) —
 * as three explicit methods rather than one method with boolean flags, so a call site
 * reads exactly which channels a given notification uses.
 */
final class NotificationService
{
    public function notifyInApp(User $user, string $type, string $title, ?string $body, ?string $linkUrl): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link_url' => $linkUrl,
        ]);
    }

    public function notifyInAppAndEmail(User $user, string $type, string $title, ?string $body, ?string $linkUrl): Notification
    {
        $notification = $this->notifyInApp($user, $type, $title, $body, $linkUrl);

        if (! empty($user->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($user->email)->send(new NotificationMail($title, $body, $linkUrl));
            } catch (\Throwable $e) {
                Log::warning("Failed to send notification email to {$user->email}: {$e->getMessage()}");
            }
        }

        return $notification;
    }

    public function emailOnly(User $user, string $title, ?string $body, ?string $linkUrl): void
    {
        if (! empty($user->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($user->email)->send(new NotificationMail($title, $body, $linkUrl));
            } catch (\Throwable $e) {
                Log::warning("Failed to send notification email to {$user->email}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Recipient pool for the daily/system-wide checks: every active user holding at least
     * one of the given permission codes, deduplicated (a user could hold more than one).
     *
     * @return Collection<int, User>
     */
    public function usersWithAnyPermission(string ...$codes): Collection
    {
        return User::whereHas('roles.permissions', fn ($q) => $q->whereIn('code', $codes))
            ->where('is_active', true)
            ->get()
            ->unique('id');
    }

    /**
     * Who may decide a requisition that is waiting (approvals.md K, 2026-11-04): every active holder of
     * `requisition.approve_scientist` in the requisition's own branch — each scientist of the branch (all kinds
     * of stock), plus the warehouse managers (LAB_MANAGER/AUDITOR) who are responsible for the kind(s) of stock in
     * it (every kind, for a legacy mixed one; ITM-2610-052 phase 3). Mirrors RequisitionPolicy::scientistDecide().
     *
     * @return Collection<int, User>
     */
    public function approversFor(Requisition $requisition): Collection
    {
        $kinds = $requisition->itemKinds();

        return User::whereHas('roles.permissions', fn ($q) => $q->where('code', 'requisition.approve_scientist'))
            ->where('is_active', true)
            ->whereNotNull('lab_id')
            ->where('lab_id', $requisition->lab_id)
            ->with(['roles', 'itemTypes'])
            ->get()
            ->filter(fn (User $user) => ! $user->isBranchManager() || ($kinds !== [] && array_diff($kinds, $user->managedItemTypes()) === []))
            ->values();
    }

    /**
     * FR-NT-03: a requisition just became actionable. Scientists rotate duty by day and the system does not know
     * who is on duty, so everyone in the branch who may decide it gets the IN-SYSTEM notice, but only the warehouse
     * managers responsible for it ALSO get an email — never an email to every scientist for every requisition. If
     * nobody in that branch may decide it, tell every ADMIN to go and fix the roster (ADMIN cannot approve
     * requisitions themselves), so it never waits unseen.
     */
    public function notifyApprovalPending(Requisition $requisition): void
    {
        $link = route('requisitions.show', $requisition);
        $approvers = $this->approversFor($requisition);

        if ($approvers->isNotEmpty()) {
            foreach ($approvers as $approver) {
                // The requester just filed it and needs no reminder (they may still approve it themselves).
                if ($approver->id === $requisition->requester_id) {
                    continue;
                }

                $title = (string) __('notifications.pending_scientist_title');
                $body = (string) __('notifications.pending_scientist_body', ['doc_no' => $requisition->doc_no]);

                if ($approver->isBranchManager()) {
                    $this->notifyInAppAndEmail($approver, 'requisition.pending_scientist', $title, $body, $link);
                } else {
                    $this->notifyInApp($approver, 'requisition.pending_scientist', $title, $body, $link);
                }
            }

            return;
        }

        $kindLabels = array_map(fn (string $kind) => (string) __('requisitions.request_type_'.$kind), $requisition->itemKinds());
        foreach ($this->usersWithRole('ADMIN') as $admin) {
            $this->notifyInAppAndEmail(
                $admin,
                'requisition.no_approver',
                (string) __('notifications.no_approver_title'),
                (string) __('notifications.no_approver_body', [
                    'doc_no' => $requisition->doc_no,
                    'lab' => (string) $requisition->lab?->name_th,
                    'kind' => implode(' + ', $kindLabels),
                ]),
                $link,
            );
        }
    }

    /**
     * Who may dispense an approved requisition: active holders of `requisition.issue` in its own branch — scientists
     * (all kinds) and warehouse managers responsible for its kind(s). Mirrors RequisitionPolicy::issue().
     *
     * @return Collection<int, User>
     */
    public function dispensersFor(Requisition $requisition): Collection
    {
        $kinds = $requisition->itemKinds();

        return User::whereHas('roles.permissions', fn ($q) => $q->where('code', 'requisition.issue'))
            ->where('is_active', true)
            ->whereNotNull('lab_id')
            ->where('lab_id', $requisition->lab_id)
            ->with(['roles', 'itemTypes'])
            ->get()
            ->filter(fn (User $user) => ! $user->isBranchManager() || ($kinds !== [] && array_diff($kinds, $user->managedItemTypes()) === []))
            ->values();
    }

    /**
     * approvals.md K: a student's requisition became APPROVED (advisor's decision) and is ready to dispense. Same
     * channel rule as notifyApprovalPending(): in-system to every dispenser of the branch, email only to the
     * warehouse managers, so the scientists' inboxes are never flooded.
     */
    public function notifyReadyToIssue(Requisition $requisition): void
    {
        $link = route('requisitions.show', $requisition);
        $title = (string) __('notifications.ready_to_issue_title');
        $body = (string) __('notifications.ready_to_issue_body', ['doc_no' => $requisition->doc_no]);

        foreach ($this->dispensersFor($requisition) as $dispenser) {
            if ($dispenser->isBranchManager()) {
                $this->notifyInAppAndEmail($dispenser, 'requisition.ready_to_issue', $title, $body, $link);
            } else {
                $this->notifyInApp($dispenser, 'requisition.ready_to_issue', $title, $body, $link);
            }
        }
    }

    /** @return Collection<int, User> */
    public function usersWithRole(string $roleCode): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->get();
    }
}
