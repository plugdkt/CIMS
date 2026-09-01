<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteProfileRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class CompleteProfileController extends Controller
{
    public function show(): View
    {
        $advisors = User::whereHas('roles', fn ($q) => $q->where('code', 'ADVISOR'))
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        return view('auth.complete-profile', ['advisors' => $advisors]);
    }

    public function update(CompleteProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill([
            'person_type' => $request->string('person_type')->toString(),
            'phone_encrypted' => $request->string('phone')->toString(),
            'person_code_encrypted' => $request->string('person_code')->toString(),
            'program' => $request->string('program')->toString(),
            'faculty' => $request->string('faculty')->toString(),
            'advisor_id' => $request->input('advisor_id'),
            'profile_completed_at' => now(),
        ]);
        $user->save();

        // No dashboard yet (Phase 2) — '/' is the placeholder landing spot until then.
        return redirect('/')->with('status', __('auth.complete_profile_success'));
    }
}
