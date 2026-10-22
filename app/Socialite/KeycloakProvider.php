<?php

declare(strict_types=1);

namespace App\Socialite;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * Keycloak OIDC provider — the tenant's OWN realm is the identity provider.
 *
 * Fork addition (see NOTICE). Upstream Relaticle ships Socialite with Google and
 * Microsoft only; neither can be pointed at a Keycloak realm. This is the one
 * provider the platform needs, so the CRM federates to the SAME identity that
 * signs the brand into the console — realm `tenant-<slug>`, not a shared login.
 *
 * Per-tenant, built per request
 * -----------------------------
 * The realm is not a `config/services.php` entry: this deployment is ONE
 * Relaticle serving every tenant, so a deployment-wide Keycloak client would be
 * one shared credential. Instead the caller builds the provider for a named
 * workspace (its slug is the realm), which is why the generic Filament login
 * page never offers this provider — it has no tenant to point at.
 *
 * Public client + PKCE
 * --------------------
 * The realm's `relaticle-crm` client is a public OIDC client (Authorization Code
 * + PKCE, S256). That removes the per-tenant client secret a confidential client
 * would force us to store and rotate, and `Socialite` carries the code verifier
 * itself. `client_secret` is therefore empty and is stripped from the token
 * request (Keycloak rejects a public-client exchange that carries one).
 *
 * Two base URLs, on purpose
 * -------------------------
 * The browser must be sent to the PUBLIC origin (`https://<host>/realms/…`,
 * which Caddy mounts on the deployment origin), while the token and userinfo
 * calls are made server-side over the compose network (`http://keycloak:8080`).
 * Conflating them either sends the browser to an unresolvable host or makes the
 * code exchange hairpin through the public edge.
 */
final class KeycloakProvider extends AbstractProvider implements ProviderInterface
{
    /** @var array<int, string> */
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    private string $realm = '';

    private string $internalBaseUrl = '';

    private string $publicBaseUrl = '';

    /**
     * The token response from the code exchange, retained for sign-out.
     *
     * Fork addition (see NOTICE; card t_fc36fa36). The parent reads the identity
     * out of the access token ({@see self::getUserByToken()}) and throws the
     * response away — which also throws away `id_token`, the ONE credential an
     * RP-initiated logout needs: without `id_token_hint` Keycloak cannot tell
     * which SSO session to end and answers a confirmation page instead of signing
     * out. Retained in memory only; the caller stores it with the session.
     *
     * @var array<string, mixed>|null
     */
    private ?array $tokenResponse = null;

    /**
     * Bind the provider to one tenant realm. `$internalBaseUrl` is reached by
     * this server; `$publicBaseUrl` is what the browser is sent to.
     */
    public function forRealm(string $realm, string $internalBaseUrl, string $publicBaseUrl): static
    {
        $this->realm = $realm;
        $this->internalBaseUrl = rtrim($internalBaseUrl, '/');
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');

        return $this;
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(
            "{$this->publicBaseUrl}/realms/{$this->realm}/protocol/openid-connect/auth",
            $state,
        );
    }

    protected function getTokenUrl(): string
    {
        return "{$this->internalBaseUrl}/realms/{$this->realm}/protocol/openid-connect/token";
    }

    /**
     * The identity claims, read from the ACCESS token itself.
     *
     * Keycloak's access token is a JWT whose `profile`/`email` client-scope
     * mappers carry `sub`, `preferred_username`, `email` and `name` — the same
     * claims the userinfo endpoint would return — so there is no reason to make
     * a second network call. (The userinfo endpoint additionally answers 401 to
     * a token minted through the internal base URL, because the token's issuer
     * is the PUBLIC origin and Keycloak won't accept it through the internal
     * one; reading the signed token sidesteps that entirely.)
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $parts = explode('.', (string) $token);
        if (count($parts) < 2) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return [];
        }

        $claims = json_decode($payload, true);

        return is_array($claims) ? $claims : [];
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? null,
            'name' => $user['name'] ?? ($user['preferred_username'] ?? null),
            'email' => $user['email'] ?? null,
            'avatar' => null,
        ]);
    }

    /**
     * A public client must not send `client_secret` — Keycloak answers a
     * public-client code exchange that carries one with `invalid_client`.
     *
     * @return array<string, mixed>
     */
    protected function getTokenFields($code): array
    {
        $fields = parent::getTokenFields($code);

        if ($this->clientSecret === '' || $this->clientSecret === null) {
            unset($fields['client_secret']);
        }

        return $fields;
    }

    /**
     * Keep the token response the parent discards (fork addition, t_fc36fa36).
     *
     * `AbstractProvider::user()` calls this and then reads only the access token;
     * the `id_token` it carries is the only thing that lets a later sign-out end
     * the realm's SSO session without prompting. Memory-only: the callback reads
     * {@see self::idToken()} immediately and writes it to the session.
     *
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code)
    {
        $response = parent::getAccessTokenResponse($code);

        $this->tokenResponse = is_array($response) ? $response : null;

        return $response;
    }

    /** The OIDC id_token from the last code exchange, or null when absent. */
    public function idToken(): ?string
    {
        $token = $this->tokenResponse['id_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
