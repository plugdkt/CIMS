<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-AU-07: assign/remove roles, toggle is_active — ADMIN only (enforced via UserPolicy).
 * No #[Title] attribute: PHP attribute arguments can't call __() (rule #11 bars hardcoded
 * Thai), so the browser tab falls back to the layout's default app-name title.
 */
#[Layout('components.layout')]
final class UserRoleManager extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::orderBy('id')->get();
    }

    /** @return Collection<int, Lab> */
    #[Computed]
    public function labs(): Collection
    {
        return Lab::orderBy('name_th')->get();
    }

    /** Branch assignment (`users.lab_id`) — who belongs to which lab, per the multi-branch
     *  access-control feature. `$labId` of `null` clears the assignment. */
    public function setLab(int $userId, ?int $labId): void
    {
        /** @var User $target */
        $target = User::findOrFail($userId);
        $this->authorize('manageRoles', $target);

        $before = $target->lab_id;
        $target->update(['lab_id' => $labId]);

        AuditLog::record(
            action: 'LAB_ASSIGN',
            userId: $this->authId(),
            username: auth()->user()?->username,
            entityType: User::class,
            entityId: $target->id,
            oldValue: ['lab_id' => $before],
            newValue: ['lab_id' => $labId],
        );
    }

    public function toggleRole(int $userId, int $roleId): void
    {
        /** @var User $target */
        $target = User::findOrFail($userId);
        $this->authorize('manageRoles', $target);

        $before = $target->roles->pluck('code')->all();

        if ($target->roles->contains('id', $roleId)) {
            $target->roles()->detach($roleId);
        } else {
            $target->roles()->attach($roleId);
        }

        $after = $target->roles()->pluck('code')->all();

        AuditLog::record(
            action: 'ROLE_CHANGE',
            userId: $this->authId(),
            username: auth()->user()?->username,
            entityType: User::class,
            entityId: $target->id,
            oldValue: ['roles' => $before],
            newValue: ['roles' => $after],
        );
    }

    public function toggleActive(int $userId): void
    {
        /** @var User $target */
        $target = User::findOrFail($userId);
        $this->authorize('manageRoles', $target);

        $before = $target->is_active;
        $target->update(['is_active' => ! $target->is_active]);

        AuditLog::record(
            action: 'USER_ACTIVE_TOGGLE',
            userId: $this->authId(),
            username: auth()->user()?->username,
            entityType: User::class,
            entityId: $target->id,
            oldValue: ['is_active' => $before],
            newValue: ['is_active' => $target->is_active],
        );
    }

    private function authId(): ?int
    {
        $id = auth()->id();

        return $id !== null ? (int) $id : null;
    }

    public function render(): View
    {
        $users = User::query()
            ->with('roles')
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('full_name', 'like', "%{$this->search}%")
                        ->orWhere('username', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('full_name')
            ->paginate(20);

        return view('livewire.admin.user-role-manager', ['users' => $users]);
    }
}
