<?php

declare(strict_types=1);

namespace App\Support\Platform;

use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

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
 * 2. **The chrome carries the tenant's name, not the product's.** This is one
 *    deployment serving every tenant behind the reseller's own domains, so the
 *    vendor mark on the CRM surface names the wrong party.
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
 * The branding half deliberately falls back to `config('app.name')` for every
 * other caller, so a stock self-host deployment still renders its own name.
 */
final class TenantSurface
{
    /** The Socialite provider the federated sign-in links under. */
    public const string PROVIDER = 'keycloak';

    /** `provider_id` is `<realm>|<sub>`; a tenant realm is named `tenant-<slug>`. */
    private const string TENANT_REALM_PREFIX = 'tenant-';

    /**
     * Per-request memo. Several chrome elements ask for the brand on one render
     * (the <title>, the sidebar logo, the footer) and the answer cannot change
     * within a request: a federation link is written on the callback, then the
     * browser is redirected. Not a cross-request cache: php-fpm gives every
     * request a fresh process.
     *
     * @var array<string, bool>
     */
    private static array $tenantIdentities = [];

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

        return self::$tenantIdentities[$key] ??= UserSocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider_name', self::PROVIDER)
            ->where('provider_id', 'like', self::TENANT_REALM_PREFIX.'%')
            ->exists();
    }

    /**
     * The name the tenant-facing chrome renders: the bound workspace's name for a
     * tenant session, the deployment's own name otherwise.
     */
    public static function brandName(?Authenticatable $user = null): string
    {
        if (! self::isTenantSession($user)) {
            return (string) config('app.name');
        }

        $tenant = Filament::getTenant();

        if ($tenant instanceof Workspace && filled($tenant->name)) {
            return (string) $tenant->name;
        }

        // A tenant session with no bound tenant (a redirect in flight): the
        // deployment name beats an empty header.
        return (string) config('app.name');
    }

    /**
     * Forget the memoised answers. Only needed by tests that swap the acting user
     * inside one process.
     */
    public static function forget(): void
    {
        self::$tenantIdentities = [];
    }
}
