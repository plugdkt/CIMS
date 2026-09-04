<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** The in-app notification bell (FR-NT-01..06) — every user reads only their own. */
final class NotificationController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Notification::class);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $notifications = $user->notifications()->paginate(20);

        return view('notifications.index', ['notifications' => $notifications]);
    }

    public function read(Notification $notification): RedirectResponse
    {
        $this->authorize('update', $notification);

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification->link_url !== null
            ? redirect($notification->link_url)
            : redirect()->route('notifications.index');
    }

    public function readAll(): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $user->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->route('notifications.index');
    }
}
