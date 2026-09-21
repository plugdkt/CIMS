<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteProfileRequest;
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

        $advisors = User::whereHas('roles', fn ($q) => $q->where('code', 'ADVISOR'))
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

        $user->fill([
            'person_type' => $request->string('person_type')->toString(),
            'phone_encrypted' => $request->string('phone')->toString(),
            'person_code_encrypted' => $request->string('person_code')->toString(),
            'program' => $request->string('program')->toString(),
            'faculty' => $request->string('faculty')->toString(),
            'lab_id' => $request->integer('lab_id'),
            'advisor_id' => $request->input('advisor_id'),
            'profile_completed_at' => now(),
        ]);
        $user->save();

        return redirect()->intended(route('requisitions.create'))
            ->with('status', __('auth.complete_profile_success'));
    }
}
