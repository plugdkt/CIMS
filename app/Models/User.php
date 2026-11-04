<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ItemType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * v1.1.0: no local credentials at all (SEC-AU-01) — every attribute here is either
 * synced verbatim from the MEDSCI ACC SSO payload, or a CMIS-only field collected via
 * the post-login "complete your profile" step (BR-11).
 *
 * @property \Illuminate\Support\Carbon|null $profile_completed_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $last_sso_sync_at
 * @property \Illuminate\Support\Carbon|null $privacy_consent_at
 * @property \Illuminate\Support\Carbon|null $pseudonymized_at
 */
#[Fillable([
    'ulid', 'sso_subject', 'username', 'email', 'full_name', 'pos_name', 'div_name',
    'phone_encrypted', 'person_code_encrypted', 'person_type', 'program', 'faculty',
    'lab_id', 'advisor_id', 'profile_completed_at', 'is_active',
    'last_login_at', 'last_sso_sync_at',
    'privacy_consent_at', 'privacy_consent_version', 'pseudonymized_at',
])]
#[Hidden(['phone_encrypted', 'person_code_encrypted'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'profile_completed_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'last_sso_sync_at' => 'datetime',
            'privacy_consent_at' => 'datetime',
            'pseudonymized_at' => 'datetime',
            // SEC-CR-05: AES-256-GCM (app-wide cipher, see config/app.php) via Laravel's
            // built-in encrypted cast.
            'phone_encrypted' => 'encrypted',
            'person_code_encrypted' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<self, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'advisor_id');
    }

    /** @return HasMany<self, $this> */
    public function advisees(): HasMany
    {
        return $this->hasMany(self::class, 'advisor_id');
    }

    /** @return HasMany<UserItemType, $this> */
    public function itemTypes(): HasMany
    {
        return $this->hasMany(UserItemType::class);
    }

    /**
     * ITM-2610-052 phase 3: the kinds of stock (App\Domain\Shared\ItemType slugs) this account is
     * responsible for. ADMIN holds every kind (and still cannot decide requisitions: that needs
     * `requisition.approve_scientist`, which ADMIN does not have). Everyone else holds exactly the rows in
     * `user_item_types` — none by default (deny by default). Only warehouse managers
     * (isBranchManager) are narrowed by this; other roles never consult it.
     *
     * @return list<string>
     */
    public function managedItemTypes(): array
    {
        if ($this->hasRole('ADMIN')) {
            return ItemType::all();
        }

        return array_values($this->itemTypes->pluck('item_type')->map(fn ($type) => (string) $type)->all());
    }

    public function managesItemType(string $slug): bool
    {
        return in_array($slug, $this->managedItemTypes(), true);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRole(string $code): bool
    {
        return $this->roles->contains('code', $code);
    }

    /**
     * Post-launch, 2026-09-21 (user-requested): AUDITOR was repurposed from a
     * read-only oversight role into a second, independently-assignable flavor of
     * branch-scoped warehouse manager — same operational grants and own-branch-only
     * scoping as LAB_MANAGER (name_th "ผู้ดูแลคลัง"), just a separate role code so
     * both can be assigned/removed independently. Every place that used to gate
     * "is this a branch-scoped manager" on `hasRole('LAB_MANAGER')` alone now calls
     * this instead, so both roles are always scoped identically.
     */
    public function isBranchManager(): bool
    {
        if ($this->hasRole('ADMIN')) {
            return false;
        }

        return $this->hasRole('LAB_MANAGER') || $this->hasRole('AUDITOR');
    }

    /**
     * BR-02 hardening (CEO 2026-11-02): once the profile has been completed with a person type, that
     * type (STUDENT / STAFF / LECTURER) can no longer be changed from the complete-profile page — a
     * student must not turn themselves into STAFF to skip the advisor step. An older account whose
     * profile was flagged complete but has no person type yet is not locked and can still fill it in.
     */
    public function personTypeLocked(): bool
    {
        return $this->profile_completed_at !== null && $this->person_type !== null;
    }

    /**
     * The person type this account is forced to, or null when the user may choose.
     * - Locked profile: the stored type (even if it looks wrong; an admin fixes that in the database).
     * - Holds the STUDENT role (assigned by an admin, never by the user): always STUDENT, so a student
     *   cannot pick STAFF/LECTURER at the first fill-in to skip the advisor step (BR-02).
     */
    public function personTypeFixed(): ?string
    {
        if ($this->personTypeLocked()) {
            return $this->person_type;
        }

        return $this->hasRole('STUDENT') ? 'STUDENT' : null;
    }

    /** Same idea for the advisor (CEO 2026-11-02): once set on a completed profile, the user cannot change it themselves. */
    public function advisorLocked(): bool
    {
        return $this->profile_completed_at !== null && $this->advisor_id !== null;
    }

    /**
     * ITM-2610-051 (BR-L05): the branch decides who may approve a loan and which assets a person may borrow, so a
     * user must not be able to move themselves to another branch from the complete-profile page. Once a branch is
     * set (by the first fill-in, an ADMIN, a branch manager or the import) only an ADMIN changes it, in
     * `UserRoleManager::setLab()` (audited). A user with no branch can still choose one once.
     */
    public function labLocked(): bool
    {
        return $this->lab_id !== null;
    }

    public function hasPermission(string $code): bool
    {
        return $this->roles->flatMap(fn ($role) => $role->permissions)->contains('code', $code);
    }

    /** SEC-PD-02: consented, and to the currently published notice (not a stale one). */
    public function hasValidPrivacyConsent(): bool
    {
        return $this->privacy_consent_at !== null
            && $this->privacy_consent_version === config('privacy.notice_version');
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->orderByDesc('id');
    }
}
