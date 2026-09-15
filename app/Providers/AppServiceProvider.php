<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Attachments\Contracts\VirusScanner;
use App\Domain\Attachments\Services\ClamAvScanner;
use App\Domain\Auth\Services\SsoClient;
use App\Domain\Chemicals\Services\PubChemClient;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SsoClient::class, fn () => new SsoClient(
            clientId: (string) config('services.sso.client_id'),
            clientSecret: (string) config('services.sso.client_secret'),
            loginUrl: (string) config('services.sso.login_url'),
            verifyUrl: (string) config('services.sso.verify_url'),
            logoutUrl: (string) config('services.sso.logout_url'),
            callbackUrl: (string) config('services.sso.callback_url'),
            caBundle: config('services.sso.ca_bundle'),
        ));

        $this->app->singleton(VirusScanner::class, fn () => new ClamAvScanner(
            host: (string) config('attachments.virus_scan.host'),
            port: (int) config('attachments.virus_scan.port'),
        ));

        $this->app->singleton(PubChemClient::class, fn () => new PubChemClient(
            pugBaseUrl: (string) config('services.pubchem.pug_base_url'),
            pugViewBaseUrl: (string) config('services.pubchem.pug_view_base_url'),
            timeoutSeconds: (int) config('services.pubchem.timeout_seconds'),
        ));
    }

    public function boot(): void
    {
        // spec §5.2's attachments.owner_type comment shows short names ("Item /
        // Requisition / GoodsReceipt"), not full class paths. Only Item exists so far —
        // add Requisition/GoodsReceipt here once those models land (T-022/T-030+).
        Relation::enforceMorphMap([
            'Item' => Item::class,
        ]);

        // Bridges the seeded permission catalog (PermissionSeeder) to Laravel's Gate,
        // so `$user->can('requisition.approve_advisor')` etc. work without registering
        // every permission code by hand. Returns null (not false) for unknown abilities
        // so named Policy methods (e.g. UserPolicy::manageRoles) still run normally —
        // SEC-AZ-01 deny-by-default holds because a user with no matching permission
        // simply gets null here and falls through to Laravel's default "no policy, no
        // gate ⇒ deny" behavior.
        Gate::before(function (User $user, string $ability) {
            $hasPermission = $user->roles->flatMap(fn ($role) => $role->permissions)->contains('code', $ability);

            return $hasPermission ? true : null;
        });

        $basePath = trim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');
        if ($basePath !== '') {
            \Livewire\Livewire::setScriptRoute(function ($handle) use ($basePath) {
                return \Illuminate\Support\Facades\Route::get("{$basePath}/livewire/livewire.js", $handle);
            });

            \Livewire\Livewire::setUpdateRoute(function ($handle) use ($basePath) {
                return \Illuminate\Support\Facades\Route::post("{$basePath}/livewire/update", $handle);
            });
        }
    }
}
