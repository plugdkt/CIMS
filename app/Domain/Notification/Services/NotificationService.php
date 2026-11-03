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
     * ITM-2610-052 phase 3: who is told that a requisition is waiting for a warehouse decision — the
     * active managers of the requisition's own branch who hold approval rights and are responsible
     * for the kind(s) of stock in it (every kind, for a legacy mixed one). Before phase 3 this went to
     * every approver of every branch.
     *
     * @return Collection<int, User>
     */
    public function approversFor(Requisition $requisition): Collection
    {
        $kinds = $requisition->itemKinds();

        return User::whereHas('roles.permissions', fn ($q) => $q->where('code', 'requisition.approve_scientist'))
            ->where('is_active', true)
            ->where('lab_id', $requisition->lab_id)
            ->with(['roles', 'itemTypes'])
            ->get()
            ->filter(fn (User $user) => $kinds !== [] && array_diff($kinds, $user->managedItemTypes()) === [])
            ->values();
    }

    /**
     * FR-NT-03: a requisition just became actionable by the warehouse. Tell its approvers (see
     * approversFor()); if nobody in that branch is responsible for its kind, tell every ADMIN to go and
     * assign a manager (ADMIN cannot approve requisitions themselves), so it never waits unseen.
     */
    public function notifyApprovalPending(Requisition $requisition): void
    {
        $link = route('requisitions.show', $requisition);
        $approvers = $this->approversFor($requisition);

        if ($approvers->isNotEmpty()) {
            foreach ($approvers as $approver) {
                $this->notifyInAppAndEmail(
                    $approver,
                    'requisition.pending_scientist',
                    (string) __('notifications.pending_scientist_title'),
                    (string) __('notifications.pending_scientist_body', ['doc_no' => $requisition->doc_no]),
                    $link,
                );
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

    /** @return Collection<int, User> */
    public function usersWithRole(string $roleCode): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->get();
    }
}
