<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Support\Platform\KeycloakEndSession;
use App\Support\Platform\PlatformConsole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `GET /auth/logout` — the platform's server-side sign-out for this CRM session.
 *
 * Fork addition (see NOTICE; card t_fc36fa36). The console's sign-out reaches
 * this route from the ORCHESTRATOR, not from the browser: console and CRM are one
 * origin and the session cookie is on `Path=/`, so the orchestrator forwards the
 * browser's own `relaticle_session` value on one bounded, fire-and-forget call
 * (`orchestrator/src/routes/console-auth.ts`, `revokeCrmSession`).
 *
 * WHY A NEW ROUTE when Filament already revokes: its `POST /app/logout` is a
 * CSRF-guarded form post, and a sign-out that has already navigated away cannot
 * render a form and post a token. This route is a GET for exactly that reason —
 * Logout-CSRF is an accepted trade, and it can revoke nothing but the session
 * that presents itself. It performs the SAME three calls Filament's controller
 * makes, so the `sessions` row is deleted rather than merely un-flagged:
 * `auth()->logout()` → `session()->invalidate()` → `session()->regenerateToken()`
 * (`vendor/filament/filament/src/Auth/Http/Controllers/LogoutController.php`).
 *
 * NOT under `auth`/`guest`: a lapsed or already-revoked session must answer
 * `200 {"revoked": false}`. The caller cannot follow a redirect, and a 302 to the
 * login page would read as a failed revoke; a 5xx would read as a broken one.
 *
 * `?continue=1` is the browser shape (a session that entered the CRM directly):
 * after revoking, it 302s to the realm's end-session URL (RP-initiated logout).
 * Without it the answer is JSON, which is what the orchestrator gets.
 */
final class LogoutController
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        // Read BEFORE invalidate(): invalidate() flushes the session store.
        $retained = $request->session()->get(KeycloakEndSession::SESSION_KEY);
        $wasAuthenticated = Auth::guard('web')->check();

        if ($wasAuthenticated) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $endSessionUrl = KeycloakEndSession::urlFor($retained);

        if ($request->boolean('continue')) {
            // Realm sign-out if one was recorded, else the console's own door —
            // never this deployment's login page (contract §8 item 3).
            return redirect()->away($endSessionUrl ?? PlatformConsole::url() ?? url('/'));
        }

        return response()
            ->json(['revoked' => $wasAuthenticated, 'end_session_url' => $endSessionUrl])
            ->header('Cache-Control', 'no-store');
    }
}
