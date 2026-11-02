<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteProfileRequest;
use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CompleteProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        // Same rule CompleteProfileRequest enforces: active, holds requisition.approve_advisor, and never yourself.
        $advisors = User::where('is_active', true)
            ->where('id', '!=', $user?->id)
            ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'requisition.approve_advisor'))
            ->with('lab')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'lab_id']);

        $labs = Lab::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_th']);

        return view('auth.complete-profile', [
            'user' => $user,
            'advisors' => $advisors,
            'labs' => $labs,
        ]);
    }

    public function update(CompleteProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $before = $this->snapshot($user);
        $advisorInput = $user->advisorLocked() ? $user->advisor_id : $request->input('advisor_id');

        $user->fill([
            // A locked person type is kept as stored (the request already refused any other value).
            'person_type' => $request->effectivePersonType() ?? '',
            // Blank (only possible for ADMIN, see CompleteProfileRequest) is stored as NULL.
            'phone_encrypted' => $this->blankToNull($request->string('phone')->toString()),
            'person_code_encrypted' => $this->blankToNull($request->string('person_code')->toString()),
            'program' => $request->string('program')->toString(),
            'faculty' => $request->string('faculty')->toString(),
            'lab_id' => $request->integer('lab_id'),
            // A locked advisor stays as stored (the request already refused any other value).
            'advisor_id' => $advisorInput === null || $advisorInput === '' ? null : (int) $advisorInput,
            'profile_completed_at' => now(),
        ]);
        $user->save();

        // Audit trail for the three fields that decide who approves what (reference ids only).
        $after = $this->snapshot($user);
        $changed = array_keys(array_filter($after, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            AuditLog::record(
                action: 'PROFILE_APPROVAL_FIELDS_CHANGE',
                userId: $user->id,
                username: $user->username,
                entityType: User::class,
                entityId: $user->id,
                oldValue: array_intersect_key($before, array_flip($changed)),
                newValue: array_intersect_key($after, array_flip($changed)),
            );
        }

        return redirect()->intended(route('requisitions.create'))
            ->with('status', __('auth.complete_profile_success'));
    }

    /**
     * The three audited fields with consistent types (ids as int|null), so old and new values in
     * audit_logs are comparable whatever shape the form input had.
     *
     * @return array{person_type: string|null, advisor_id: int|null, lab_id: int|null}
     */
    private function snapshot(User $user): array
    {
        return [
            'person_type' => $user->person_type,
            'advisor_id' => $user->advisor_id === null ? null : (int) $user->advisor_id,
            'lab_id' => $user->lab_id === null ? null : (int) $user->lab_id,
        ];
    }

    /** Only an empty string is "blank" — a value like "0" must not be dropped by PHP truthiness. */
    private function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
