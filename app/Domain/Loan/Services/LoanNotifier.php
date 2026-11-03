<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Models\LoanAsset;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ITM-2610-051: notifications of the lending module (a class of its own; NotificationService is only used,
 * not changed). Called by the controller AFTER the ledger write has succeeded, so a mail or database problem
 * here can never undo or block the recorded movement: every failure is caught and logged.
 *
 * Step 1 sends one kind: the person named as approver of an ADJUST / RETIRE is told, in the system and by
 * email. The text says plainly that being named is NOT a confirmation (a real confirm step by the named
 * person comes with step 2) and tells them whom to contact if they know nothing about it. Only a reference
 * the receiver already sees in the system is included: the actor's display name, the item, the branch and
 * the reason; no phone, code or other personal data.
 */
final class LoanNotifier
{
    public const TYPE_APPROVER_NAMED = 'loan.approver_named';

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * @param  'adjust_in'|'adjust_out'|'retire'  $action
     */
    public function approverNamed(User $actor, LoanAsset $asset, string $action, int $approverId, string $reason): void
    {
        try {
            $approver = User::find($approverId);
            // Never to the person who did it (the ledger already forbids that), nor to an account that cannot take it.
            if ($approver === null || $approver->id === $actor->id || ! $approver->is_active) {
                return;
            }

            $asset->loadMissing(['item', 'lab']);

            $this->notifications->notifyInAppAndEmail(
                $approver,
                self::TYPE_APPROVER_NAMED,
                (string) __('loans.notify.approver_named_title'),
                (string) __('loans.notify.approver_named_body', [
                    'actor' => $actor->full_name,
                    'action' => (string) __('loans.notify.action_'.$action),
                    'item' => (string) $asset->item?->name_th,
                    'lab' => (string) $asset->lab?->name_th,
                    'reason' => Str::limit(trim($reason), 200),
                ]),
                route('loans.assets.show', $asset),
            );
        } catch (Throwable $e) {
            Log::warning("Failed to notify the named loan approver for asset #{$asset->id}: {$e->getMessage()}");
        }
    }
}
