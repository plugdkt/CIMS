<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\DTO\SsoUserData;
use App\Domain\Auth\Exceptions\SsoVerificationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SsoClient
{
    private const STATE_SESSION_KEY = 'sso_state';

    private const TOKEN_TTL_SECONDS = 120;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $loginUrl,
        private readonly string $verifyUrl,
        private readonly string $logoutUrl,
        private readonly string $callbackUrl,
    ) {
    }

    /** Builds the redirect URL and stores the CSRF `state` in session (SEC-AU-02). */
    public function buildLoginUrl(): string
    {
        $state = Str::random(32);
        session([self::STATE_SESSION_KEY => $state]);

        return $this->loginUrl.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->callbackUrl,
            'state' => $state,
        ]);
    }

    /**
     * Checks `state` then verifies `token` server-to-server. Throws on any failure —
     * never calls the verify API when `state` doesn't match (SEC-AU-02).
     */
    public function handleCallback(string $token, string $state): SsoUserData
    {
        $savedState = session(self::STATE_SESSION_KEY);
        session()->forget(self::STATE_SESSION_KEY);

        if ($token === '' || $savedState === null || ! hash_equals((string) $savedState, $state)) {
            throw new SsoVerificationException('Invalid SSO state (possible Login CSRF)');
        }

        $response = Http::asForm()
            ->timeout(self::TOKEN_TTL_SECONDS)
            ->post($this->verifyUrl, [
                'token' => $token,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        $result = $response->json();

        if (! $response->ok() || ! is_array($result) || ($result['status'] ?? null) !== 'success') {
            $message = is_array($result) ? ($result['message'] ?? null) : null;

            throw new SsoVerificationException(is_string($message) ? $message : 'SSO token verification failed');
        }

        return SsoUserData::fromArray($result['user']);
    }

    public function logoutUrl(string $returnUrl): string
    {
        return $this->logoutUrl.'?'.http_build_query(['redirect_uri' => $returnUrl]);
    }
}
