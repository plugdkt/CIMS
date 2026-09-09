<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Requisition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * SEC-PD-03: the "access / copy of my data" right — every action here reads only the
 * currently authenticated user's own data, so there is no object-level access decision
 * for a Policy to make (same reasoning as `NotificationController::readAll()`).
 */
final class AccountDataController extends Controller
{
    public function show(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $user->loadMissing(['roles', 'lab', 'advisor']);

        $requisitions = Requisition::where('requester_id', $user->id)
            ->orderByDesc('id')
            ->get(['ulid', 'doc_no', 'status', 'doc_date']);

        return view('privacy.my-data', ['user' => $user, 'requisitions' => $requisitions]);
    }

    public function export(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $user->loadMissing(['roles', 'lab', 'advisor']);

        $requisitions = Requisition::where('requester_id', $user->id)
            ->orderByDesc('id')
            ->get(['doc_no', 'status', 'doc_date']);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone_encrypted,
                'person_code' => $user->person_code_encrypted,
                'person_type' => $user->person_type,
                'program' => $user->program,
                'faculty' => $user->faculty,
                'lab' => $user->lab?->name_th,
                'advisor' => $user->advisor?->full_name,
                'roles' => $user->roles->pluck('code')->all(),
                'privacy_consent_at' => $user->privacy_consent_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'requisitions' => $requisitions->map(fn (Requisition $r) => [
                'doc_no' => $r->doc_no,
                'status' => $r->status,
                'doc_date' => $r->doc_date->toDateString(),
            ])->all(),
        ];

        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="cmis-my-data-'.$user->username.'.json"',
        ]);
    }
}
