<?php

declare(strict_types=1);

namespace App\Livewire\Labs;

use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A branch manager's (LAB_MANAGER/AUDITOR, see User::isBranchManager()) whitelist of who
 * may requisition from their own branch — literally `users.lab_id` (see CLAUDE.md: no
 * separate whitelist table). Candidates are limited to
 * the two roles that actually hold `requisition.create` (STUDENT/STAFF, per
 * `PermissionSeeder`) — ADVISOR/SCIENTIST/etc. never requisition, so they're not part of
 * this whitelist. A manager can only add someone currently unassigned or already in their
 * own lab, and can only remove someone already in their own lab — never poach a member
 * assigned to a different branch (that still requires ADMIN via `UserRoleManager::setLab()`).
 */
#[Layout('components.layout')]
final class LabMemberManager extends Component
{
    use WithPagination;

    private const CANDIDATE_ROLES = ['STUDENT', 'STAFF'];

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('manageMembers', Lab::class);
    }

    public function assign(int $userId): void
    {
        $this->authorize('manageMembers', Lab::class);

        /** @var User $manager */
        $manager = auth()->user();
        if ($manager->lab_id === null) {
            return;
        }

        /** @var User $target */
        $target = User::findOrFail($userId);
        $isCandidate = $target->hasRole('STUDENT') || $target->hasRole('STAFF');
        if (! $isCandidate || ! in_array($target->lab_id, [null, $manager->lab_id], true)) {
            return;
        }

        $before = $target->lab_id;
        $target->update(['lab_id' => $manager->lab_id]);

        AuditLog::record(
            action: 'LAB_MEMBER_ASSIGN',
            userId: $this->authId(),
            username: $manager->username,
            entityType: User::class,
            entityId: $target->id,
            oldValue: ['lab_id' => $before],
            newValue: ['lab_id' => $manager->lab_id],
        );
    }

    public function remove(int $userId): void
    {
        $this->authorize('manageMembers', Lab::class);

        /** @var User $manager */
        $manager = auth()->user();

        /** @var User $target */
        $target = User::findOrFail($userId);
        if ($target->lab_id !== $manager->lab_id) {
            return;
        }

        $target->update(['lab_id' => null]);

        AuditLog::record(
            action: 'LAB_MEMBER_REMOVE',
            userId: $this->authId(),
            username: $manager->username,
            entityType: User::class,
            entityId: $target->id,
            oldValue: ['lab_id' => $manager->lab_id],
            newValue: ['lab_id' => null],
        );
    }

    private function authId(): ?int
    {
        $id = auth()->id();

        return $id !== null ? (int) $id : null;
    }

    public function render(): View
    {
        /** @var User $manager */
        $manager = auth()->user();

        $candidates = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('code', self::CANDIDATE_ROLES))
            ->where(function ($q) use ($manager) {
                $q->whereNull('lab_id')->orWhere('lab_id', $manager->lab_id);
            })
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('full_name', 'like', "%{$this->search}%")
                        ->orWhere('username', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('full_name')
            ->paginate(20);

        return view('livewire.labs.lab-member-manager', [
            'manager' => $manager,
            'candidates' => $candidates,
        ]);
    }
}
