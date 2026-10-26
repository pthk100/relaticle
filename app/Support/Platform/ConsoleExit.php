<?php

declare(strict_types=1);

namespace App\Support\Platform;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The CRM's `← Console` exit (fork addition — see NOTICE; card t_619a7e0f).
 *
 * `design/CONTRACT.md` §11 requirement 7: every module has *"a way back to the
 * console; no trapping, and no module-owned logout that strands the user"*. The
 * two module SPAs that honour it each own ONE builder
 * (`chattermate/frontend/src/composables/useConsoleJump.ts`,
 * `automation/src/ConsoleJump.tsx`); this is the CRM's, and it produces the SAME
 * URL shape — `/console/?realm=<slug>&returnTo=<url-encoded path>`. One shape
 * across three codebases, not three shapes.
 *
 * WHY THE BUILDER LIVES SERVER-SIDE. Relaticle is ONE deployment serving every
 * tenant (see {@see TenantSurface}), so the realm is not a per-deployment
 * constant and must never be a value the browser can type in: it is read from
 * the two server-side records that name it —
 *
 *   1. the session entry the federation callback wrote
 *      (`KeycloakFederationController` → `KeycloakEndSession::SESSION_KEY`);
 *   2. the federated identity link that marks this session as a tenant's
 *      (`TenantSurface::tenantRealm()`), which also covers a session established
 *      before the retained id_token existed.
 *
 * Nothing here is hard-coded. A session that has neither (a stock self-host local
 * user, the platform operator) gets the realm omitted, which is the documented
 * fallback: the console's own front door asks which workspace, rather than this
 * link guessing one.
 *
 * WHAT `returnTo` CARRIES. The path the person is actually on, prefix included
 * (`/crm/app/leads?...`). nginx hands PHP the UNREWRITTEN `REQUEST_URI`
 * (`docker/relaticle/nginx-crm-prefix.conf`), so `getRequestUri()` is the
 * browser's own path — the same "where this module is" the other two builders
 * send. A pathInfo-relative value would drop `/crm` and land the person on a
 * URL the edge does not serve after they sign in.
 *
 * NO CONSOLE, NO CONTROL. With `PLATFORM_CONSOLE_URL` unset the deployment is a
 * stock self-host install ({@see PlatformConsole}) with no console to return to;
 * {@see href()} then answers null and the chrome renders nothing at all. Upstream
 * behaviour stays untouched, which is what keeps this fork cheap to rebase.
 */
final class ConsoleExit
{
    /** The visible word. The arrow is its own glyph in the view. */
    public const string LABEL = 'Console';

    /** A federated tenant realm is named `tenant-<slug>` (see TenantSurface). */
    private const string REALM_PREFIX = 'tenant-';

    /** A slug is ONE path segment: lowercase, no dot, no slash, no colon. */
    private const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * The console URL for this request, or null when this deployment declares no
     * console (in which case the chrome draws no control at all).
     */
    public static function href(Request $request): ?string
    {
        $console = PlatformConsole::url();

        if ($console === null) {
            return null;
        }

        $query = [];

        $realm = self::realmSlug($request);

        if ($realm !== null) {
            $query['realm'] = $realm;
        }

        $query['returnTo'] = self::returnTo($request);

        // RFC3986 is the encoding `encodeURIComponent`/`URLSearchParams` emit
        // (`/` → `%2F`), so the three builders' links stay byte-identical in
        // shape rather than merely similar.
        return $console
            .(str_contains($console, '?') ? '&' : '?')
            .http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The realm the console link names, or null when this session federated
     * through no tenant realm.
     */
    public static function realmSlug(Request $request, ?Authenticatable $user = null): ?string
    {
        return self::slug(self::realm($request, $user));
    }

    /**
     * This session's federated realm, from the server-side record that names it —
     * never from the request.
     */
    public static function realm(Request $request, ?Authenticatable $user = null): ?string
    {
        $retained = $request->session()->get(KeycloakEndSession::SESSION_KEY);
        $realm = is_array($retained) ? ($retained['realm'] ?? null) : null;

        if (is_string($realm) && $realm !== '') {
            return $realm;
        }

        // A session that signed in before the retained id_token existed still
        // carries the identity link the callback wrote, which names the same realm.
        return TenantSurface::tenantRealm($user);
    }

    /**
     * The path the person is on: the browser's own URI for this module, `/crm`
     * prefix included.
     *
     * A URI that is not root-absolute is not this module's location, and a link
     * built from one would leave the console for somewhere else — so it degrades
     * to the console's own root instead of emitting an unservable `returnTo`.
     */
    public static function returnTo(Request $request): string
    {
        $uri = $request->getRequestUri();

        return str_starts_with($uri, '/') ? $uri : '/';
    }

    /**
     * The control's accessible name, branded with the tenant's own name where the
     * session is a tenant's — the same wording the other two builders use.
     */
    public static function title(): string
    {
        if (! TenantSurface::isTenantSession()) {
            return (string) __('filament/panel.console.exit_title');
        }

        $brand = trim(TenantSurface::brandName());

        return $brand === ''
            ? (string) __('filament/panel.console.exit_title')
            : (string) __('filament/panel.console.exit_title_branded', ['brand' => $brand]);
    }

    /**
     * `tenant-<slug>` (or a bare `<slug>`) reduced to the slug the console link
     * carries, or null when the value could reshape the URL. The guard is the same
     * one `realmSlug()` applies in both module SPAs and in the console.
     */
    private static function slug(?string $realm): ?string
    {
        if ($realm === null || $realm === '') {
            return null;
        }

        $slug = str_starts_with($realm, self::REALM_PREFIX)
            ? substr($realm, strlen(self::REALM_PREFIX))
            : $realm;

        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }
}
