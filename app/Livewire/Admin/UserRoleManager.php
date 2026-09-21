<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-AU-07: assign/remove roles, toggle is_active — ADMIN only (enforced via UserPolicy).
 * No #[Title] attribute: PHP attribute arguments can't call __() (rule #11 bars hardcoded
 * Thai), so the browser tab falls back to the layout's default app-name title.
 *
 * User-requested 2026-09-21: personnel split into one tab per branch (Lab), plus a
 * dedicated "นิสิต" (student) tab that ignores `lab_id` entirely (a student's branch
 * doesn't matter for finding them here the way it does for staff/scientists), plus a
 * catch-all "ไม่ระบุสาขา" tab so no account — ADMIN/AUDITOR, or a freshly-provisioned
 * roleless one with no branch yet — is ever unreachable from this page.
 */
#[Layout('components.layout')]
final class UserRoleManager extends Component
{
    use WithPagination;

    private const TAB_STUDENTS = 'students';

    private const TAB_UNASSIGNED = 'unassigned';

    #[Url]
    public string $tab = '';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);

        if ($this->tab === '') {
            $firstLab = $this->labs()->first();
            $this->tab = $firstLab !== null ? (string) $firstLab->id : self::TAB_STUDENTS;
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
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
        return Lab::where('is_active', true)->orderBy('name_th')->get();
    }

    /**
     * One tab per active Lab (personnel — everyone except students, scoped to that
     * branch), then the student tab, then the unassigned catch-all — each with a
     * live count so the tab bar itself shows where people actually are.
     *
     * @return array<int|string, array{label: string, count: int}>
     */
    #[Computed]
    public function tabs(): array
    {
        $tabs = [];

        foreach ($this->labs() as $lab) {
            $tabs[(string) $lab->id] = [
                'label' => $lab->name_th,
                'count' => $this->personnelQuery()->where('lab_id', $lab->id)->count(),
            ];
        }

        $tabs[self::TAB_STUDENTS] = [
            'label' => (string) __('admin.tab_students'),
            'count' => $this->studentsQuery()->count(),
        ];

        $tabs[self::TAB_UNASSIGNED] = [
            'label' => (string) __('admin.tab_unassigned'),
            'count' => $this->personnelQuery()->whereNull('lab_id')->count(),
        ];

        return $tabs;
    }

    /** @return Builder<User> every user who is not a STUDENT (personnel: staff/scientist/lab manager/etc). */
    private function personnelQuery(): Builder
    {
        return User::query()->whereDoesntHave('roles', fn ($q) => $q->where('code', 'STUDENT'));
    }

    /** @return Builder<User> */
    private function studentsQuery(): Builder
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('code', 'STUDENT'));
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
        $users = (match ($this->tab) {
            self::TAB_STUDENTS => $this->studentsQuery(),
            self::TAB_UNASSIGNED => $this->personnelQuery()->whereNull('lab_id'),
            default => $this->personnelQuery()->where('lab_id', (int) $this->tab),
        })
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
