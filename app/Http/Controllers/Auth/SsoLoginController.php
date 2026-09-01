<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\SsoClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class SsoLoginController extends Controller
{
    public function __invoke(SsoClient $sso): RedirectResponse
    {
        return redirect()->away($sso->buildLoginUrl());
    }
}
