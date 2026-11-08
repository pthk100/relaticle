<?php

declare(strict_types=1);

namespace App\Support\Platform;

use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * The surface a platform-provisioned tenant session sees.
 *
 * Fork addition (see NOTICE). The platform creates each tenant's workspace
 * itself (`POST /api/v1/workspaces`, platform-key guarded) and hands the browser
 * to this deployment through the federated OIDC sign-in
 * (`App\Http\Controllers\Platform\KeycloakFederationController`). Two
 * consequences follow, and this class is the single place both are decided:
 *
 * 1. **A tenant must never be offered workspace creation.** The workspace is the
 *    platform's unit of tenancy; a tenant that could create its own would sit
 *    outside the orchestrator's tenant list while looking connected, and its
 *    data would live in a workspace nothing provisions, bills or tears down.
 *    Upstream's Filament registration flow exists for self-host onboarding, which
 *    is not this deployment's model.
 * 2. **The chrome carries ONE brand, not the tenant's.** The CRM module is
 *    XGATE-branded for every tenant, while the surrounding console carries the
 *    tenant's own brand (`design/DECISIONS.md`, freeze question (c)).
 *
 * WHAT MAKES A SESSION A TENANT SESSION. The federated identity link is the
 * marker: `KeycloakFederationController` stores
 * `UserSocialAccount(provider_name: 'keycloak', provider_id: '<realm>|<sub>')`
 * where `<realm>` is `tenant-<slug>`. That link exists only for identities that
 * arrived through the platform's hand-off, so it is exactly the population that
 * must not be offered workspace creation, and it leaves upstream's own
 * self-host onboarding (a local user, no link) untouched, which is what keeps
 * this fork cheap to rebase.
 *
 * The brand is the deployment's own name, so a stock self-host deployment still
 * renders its own name and this one renders XGATE.
 */
final class TenantSurface
{
    /** The Socialite provider the federated sign-in links under. */
    public const string PROVIDER = 'keycloak';

    /** `provider_id` is `<realm>|<sub>`; a tenant realm is named `tenant-<slug>`. */
    private const string TENANT_REALM_PREFIX = 'tenant-';

    /**
     * Per-request memo of the realm named by each user's federated identity link,
     * or null for a user with none. Several chrome elements ask on one render (the
     * brand, the `← Console` exit) and the answer cannot change within a request:
     * a federation link is written on the callback, then the browser is
     * redirected. Not a cross-request cache: php-fpm gives every request a fresh
     * process.
     *
     * @var array<string, string|null>
     */
    private static array $tenantRealms = [];

    /**
     * Did this session arrive through the platform's federated hand-off?
     */
    public static function isTenantSession(?Authenticatable $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $key = (string) $user->getKey();

        // Memoised together with tenantRealm() below: both read the same row, and
        // the chrome asks for both on one render (the brand and the console exit).
        if (! array_key_exists($key, self::$tenantRealms)) {
            self::$tenantRealms[$key] = self::resolveTenantRealm($user);
        }

        return self::$tenantRealms[$key] !== null;
    }

    /**
     * The realm this session federated through (`tenant-<slug>`), or null when it
     * did not arrive through the platform's hand-off.
     *
     * The full realm, not the bare slug: a caller that has to act on it (the
     * `← Console` exit, {@see ConsoleExit}) drops the prefix like every other
     * consumer of a realm does, and a caller that signs the person out wants the
     * realm Keycloak named.
     */
    public static function tenantRealm(?Authenticatable $user = null): ?string
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $key = (string) $user->getKey();

        if (! array_key_exists($key, self::$tenantRealms)) {
            self::$tenantRealms[$key] = self::resolveTenantRealm($user);
        }

        return self::$tenantRealms[$key];
    }

    /**
     * The realm half of the federated identity link (`<realm>|<sub>`), when the
     * link names a TENANT realm. Written only by the federation callback, so it is
     * exactly the population that arrived through the platform.
     */
    private static function resolveTenantRealm(User $user): ?string
    {
        $providerId = UserSocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider_name', self::PROVIDER)
            ->where('provider_id', 'like', self::TENANT_REALM_PREFIX.'%')
            ->value('provider_id');

        if (! is_string($providerId) || $providerId === '') {
            return null;
        }

        $realm = Str::before($providerId, '|');

        return $realm === '' ? null : $realm;
    }

    /**
     * The name the CRM module's chrome renders: the deployment's own name, for
     * every session. One brand serves every tenant (freeze question (c)).
     */
    public static function brandName(): string
    {
        return (string) config('app.name');
    }

    /**
     * The name the CONSOLE goes by, which is not the module's brand: the console
     * is per-tenant, the CRM module is not. The `← Console` control names the
     * place it returns to, so it reads the bound workspace's name for a tenant
     * session.
     */
    public static function consoleBrand(?Authenticatable $user = null): string
    {
        $tenant = Filament::getTenant();

        if (self::isTenantSession($user) && $tenant instanceof Workspace && filled($tenant->name)) {
            return (string) $tenant->name;
        }

        return (string) config('app.name');
    }

    /**
     * Forget the memoised answers. Only needed by tests that swap the acting user
     * inside one process.
     */
    public static function forget(): void
    {
        self::$tenantRealms = [];
    }
}
