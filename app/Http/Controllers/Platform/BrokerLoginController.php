<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Redeems a brokered sign-in token and lands the browser inside the panel.
 *
 * Fork addition — see NOTICE.
 *
 * The token is minted by WorkspaceSessionController (platform-key guarded) and
 * carries only our own ids. It is pulled from the cache, so redeeming it twice
 * fails the second time: a leaked URL is a one-shot, and it expires in two
 * minutes whether or not it is used.
 *
 * Deliberately NOT under the `guest` or `auth` middleware groups — the token IS
 * the credential, and a user who is already signed in (a stale session from
 * another tenant) must still be switched to the workspace the console named.
 */
final readonly class BrokerLoginController
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $payload = Cache::pull("platform:broker:{$token}");

        abort_unless(is_array($payload) && isset($payload['user_id'], $payload['workspace_id']), 403, 'This sign-in link has expired or was already used.');

        $user = User::query()->find($payload['user_id']);
        $workspace = Workspace::query()->find($payload['workspace_id']);

        abort_unless($user instanceof User, 403, 'This sign-in link is no longer valid.');

        // Switching identity must invalidate the previous session id, or a
        // pre-existing session cookie could keep the old tenant's context.
        Auth::login($user);
        $request->session()->regenerate();

        if ($workspace instanceof Workspace) {
            $user->switchWorkspace($workspace);
        }

        return redirect()->to(url(config('app.app_panel_path', 'app')));
    }
}
