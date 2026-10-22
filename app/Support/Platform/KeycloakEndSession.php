<?php

declare(strict_types=1);

namespace App\Support\Platform;

/**
 * RP-initiated logout against the tenant's OWN Keycloak realm.
 *
 * Fork addition (see NOTICE; card t_fc36fa36). Ending the Relaticle session is
 * only half of a sign-out: the realm's SSO cookie is what would let the next
 * hand-off skip the password, so the fork sends the browser to the realm's
 * `end_session_endpoint` with the `id_token` Keycloak issued at callback.
 *
 * WHY THE FORK AND NOT THE CONSOLE: only the party that held the `id_token` can
 * build a URL Keycloak accepts without prompting — without `id_token_hint`
 * Keycloak cannot tell which session to end and answers a confirmation page
 * (`orchestrator/src/routes/console-auth.ts:694-702` documents the neighbouring
 * failure, an unregistered `post_logout_redirect_uri`).
 *
 * PUBLIC VS INTERNAL BASE, the same split as `App\Socialite\KeycloakProvider`:
 * the browser is sent to the deployment ORIGIN (`/realms/…`, which Caddy mounts),
 * never to the compose-network dial host.
 */
final class KeycloakEndSession
{
    /** Where the callback stashes the id_token + realm for a later sign-out. */
    public const SESSION_KEY = 'platform.keycloak.end_session';

    /** The public client the CRM federates as, when `services.keycloak` is silent. */
    public const DEFAULT_CLIENT_ID = 'relaticle-crm';

    /**
     * The scheme://host the browser reaches this deployment on.
     *
     * Derived from `APP_URL`, whose path is `/crm` on this deployment — the realm
     * endpoints live at the ORIGIN (`/realms/…`), not under the `/crm` prefix.
     */
    public static function origin(): string
    {
        $parts = parse_url((string) config('app.url'));

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    /**
     * Where Keycloak returns the browser after ending the realm session: the
     * CRM's own root, which the edge already turns into the console's front door
     * (`/crm/` → `302 /console/`). Must match a registered
     * `post.logout.redirect.uris` entry on the realm client (orchestrator
     * `ensureRelaticleCrmClient`).
     */
    public static function postLogoutRedirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/';
    }

    public static function clientId(): string
    {
        $configured = config('services.keycloak.client_id');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_CLIENT_ID;
    }

    /**
     * The realm's end-session URL for one retained sign-in.
     *
     * `id_token_hint` is optional because a session established before this
     * shipped has no retained token; Keycloak then falls back to identifying the
     * session by client, which may show its confirmation page. That must be a
     * REPORTED state (the JSON body carries the URL it built), not a silent one.
     */
    public static function url(string $realm, ?string $idToken): string
    {
        $query = ['client_id' => self::clientId()];

        if ($idToken !== null && $idToken !== '') {
            $query['id_token_hint'] = $idToken;
        }

        $query['post_logout_redirect_uri'] = self::postLogoutRedirectUri();

        return self::origin().'/realms/'.$realm.'/protocol/openid-connect/logout?'.http_build_query($query);
    }

    /**
     * The url for a session's retained `[realm, id_token]`, or null when no
     * federated sign-in was recorded (a local/self-host session).
     */
    public static function urlFor(mixed $retained): ?string
    {
        if (! is_array($retained)) {
            return null;
        }

        $realm = $retained['realm'] ?? null;

        if (! is_string($realm) || $realm === '') {
            return null;
        }

        $idToken = $retained['id_token'] ?? null;

        return self::url($realm, is_string($idToken) ? $idToken : null);
    }
}
