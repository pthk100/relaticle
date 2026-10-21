<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use App\Socialite\KeycloakProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Federated CRM sign-in — Relaticle to the tenant's own Keycloak realm.
 *
 * Fork addition (see NOTICE). This is the CRM half of the platform's
 * "one signup only" decision (t_08c88bb4): a brand that signed into the console
 * against its OWN realm `tenant-<slug>` must land in ITS Relaticle workspace
 * without meeting the CRM's own login.
 *
 * FEDERATION, NOT A BROKERED CREDENTIAL. The console does not mint a Relaticle
 * session for the browser here. The browser is handed to Relaticle's Socialite
 * seam, which performs a real OIDC Authorization Code + PKCE round trip against
 * the tenant's realm; Keycloak issues the code and Relaticle exchanges it. The
 * identity that arrives is the realm user — there is no stored password and no
 * shared login.
 *
 * WHICH WORKSPACE. The tenant is named on the START request
 * (`GET /auth/keycloak/{workspace}`), where `{workspace}` route-binds to the
 * workspace whose slug IS the realm suffix. The slug is kept in the session and
 * read back on the CALLBACK — the OIDC `state`/`code` never carry it, so a
 * forged callback cannot steer the login to another tenant's workspace.
 *
 * The workspace is the "claim that identifies the workspace": the realm
 * `tenant-<slug>` is the issuer, and this controller resolves the workspace from
 * that slug. The id/access tokens carry it as the `iss` claim
 * (`…/realms/tenant-<slug>`); the client also emits a hardcoded `workspace`
 * claim equal to the slug for an explicit, quotable binding (see the Keycloak
 * client provisioner).
 *
 * IDENTITY. The realm subject (`sub`) is the identity key, scoped by realm:
 * `provider_id` is `"<realm>|<sub>"`. That keeps two tenants apart even when one
 * human administers both — `users.email` is globally unique, so the email is
 * used only when it is free, and a tenant-scoped synthetic address otherwise
 * (the rule t_9f16c775 established for exactly this collision).
 *
 * NOT under `guest` or `auth`: a stale Relaticle session from another tenant
 * must still be switched to the workspace this hand-off names.
 */
final class KeycloakFederationController
{
    /** The session key the START request stashes the workspace slug under. */
    private const SESSION_SLUG_KEY = 'platform.keycloak.workspace_slug';

    /** The Socialite provider name stored on the linked account. */
    public const PROVIDER = 'keycloak';

    /**
     * `GET /auth/keycloak/{workspace}` — begin the tenant's OIDC round trip.
     */
    public function start(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->session()->put(self::SESSION_SLUG_KEY, $workspace->slug);

        return $this->provider($request, $workspace->slug)->redirect();
    }

    /**
     * `GET /auth/keycloak/callback` — redeem the code, land in the workspace.
     */
    public function callback(Request $request): RedirectResponse
    {
        $slug = $request->session()->pull(self::SESSION_SLUG_KEY);

        abort_unless(
            is_string($slug) && $slug !== '',
            403,
            'This federated sign-in has expired. Open the CRM from your console again.',
        );

        $workspace = Workspace::query()->where('slug', $slug)->first();
        abort_unless($workspace instanceof Workspace, 403, 'Unknown workspace.');

        try {
            $social = $this->provider($request, $slug)->user();
        } catch (InvalidStateException) {
            abort(403, 'Authentication state mismatch. Please try again.');
        }

        $subject = (string) $social->getId();
        abort_if($subject === '', 403, 'The identity provider returned no subject.');

        $realm = 'tenant-'.$slug;

        $user = DB::transaction(function () use ($realm, $subject, $social, $workspace): User {
            $providerId = $realm.'|'.$subject;

            $account = UserSocialAccount::query()
                ->where('provider_name', self::PROVIDER)
                ->where('provider_id', $providerId)
                ->with('user')
                ->first();

            if ($account?->user instanceof User) {
                return $account->user;
            }

            $user = $this->resolveUser($workspace, $social);

            $user->socialAccounts()->create([
                'provider_name' => self::PROVIDER,
                'provider_id' => $providerId,
            ]);

            return $user;
        });

        $this->ensureMembership($workspace, $user);

        // Switching identity must invalidate the previous session id, so a
        // pre-existing cookie cannot keep another tenant's context.
        Auth::login($user);
        $request->session()->regenerate();
        $user->switchWorkspace($workspace);

        return redirect()->to(url(config('app.app_panel_path', 'app')));
    }

    /**
     * The Relaticle user a brokered realm identity lands as.
     *
     * The realm email is preferred only when it is free: `users.email` is
     * globally unique, so a human who administers two tenants would otherwise
     * collide on the second insert. The fallback is TENANT-SCOPED and derived
     * from the realm subject, never from the signup address (t_9f16c775).
     */
    private function resolveUser(Workspace $workspace, SocialiteUser $social): User
    {
        $email = $social->getEmail();
        $name = (string) ($social->getName() ?? '');

        if (is_string($email) && $email !== '' && ! User::query()->where('email', $email)->exists()) {
            return User::query()->create([
                'name' => $name !== '' ? $name : Str::title(Str::before($email, '@')),
                'email' => $email,
                // No human signs in with this secret: the only way in is the
                // federated OIDC round trip. Stored rather than null so the row
                // is never mistaken for a password-less "any password" account.
                'password' => Hash::make(Str::random(64)),
                // The realm vouches for the address; a provisioned owner has no
                // mailbox to click through, and the panel gates on verification.
                'email_verified_at' => now(),
            ]);
        }

        $subject = (string) $social->getId();

        return User::query()->create([
            'name' => $name !== '' ? $name : Str::title($workspace->slug),
            'email' => sprintf('%s@%s.relaticle.local', Str::lower($subject), $workspace->slug),
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ]);
    }

    /** Make the federated identity a member of the workspace it lands in. */
    private function ensureMembership(Workspace $workspace, User $user): void
    {
        if (! $workspace->users()->whereKey($user->getKey())->exists()) {
            $workspace->users()->attach($user->getKey(), ['role' => 'admin']);
        }
    }

    /**
     * Build the Keycloak provider for one tenant realm.
     *
     * The realm is `tenant-<slug>`. `config('services.keycloak')` carries only
     * deployment-wide values (the internal base URL, the public client id and
     * the public origin); nothing per-tenant is stored here — the tenant is the
     * `{workspace}` route parameter.
     */
    private function provider(Request $request, string $slug): KeycloakProvider
    {
        $internal = (string) config('services.keycloak.internal_base_url', 'http://keycloak:8080');
        $publicOrigin = (string) config('services.keycloak.public_origin', $this->publicOrigin());

        $provider = new KeycloakProvider(
            $request,
            (string) config('services.keycloak.client_id', 'relaticle-crm'),
            '', // public client — no secret (PKCE carries the proof)
            url('/auth/keycloak/callback'),
        );

        return $provider->forRealm('tenant-'.$slug, $internal, $publicOrigin);
    }

    /**
     * The scheme://host the browser reaches this deployment on. Derived from
     * `APP_URL`, whose path is `/crm` on this deployment — the realm endpoints
     * live at the ORIGIN (`/realms/…`), not under the `/crm` prefix.
     */
    private function publicOrigin(): string
    {
        $parts = parse_url((string) config('app.url'));

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
