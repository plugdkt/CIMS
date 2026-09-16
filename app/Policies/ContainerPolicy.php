<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Container;
use App\Models\User;

/** Lab inventory list ("สต็อกคงคลังย่อยของฉัน") — same read gate as the item catalog itself. */
final class ContainerPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'item.view');
    }

    public function view(User $user, Container $container): bool
    {
        return $this->hasPermission($user, 'item.view');
    }
}
