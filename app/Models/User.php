<?php

declare(strict_types=1);

namespace App\Models;

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
        return $this->hasRole('LAB_MANAGER') || $this->hasRole('AUDITOR');
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
