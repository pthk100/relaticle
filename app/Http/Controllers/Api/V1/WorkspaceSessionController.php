<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\EnsureWorkspaceOwner;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Platform-level workspace session broker (fork addition — see NOTICE).
 *
 * The console mints a MODULE session from the console login, so a brand that
 * signed up once never sees the CRM's own login. Relaticle's panel is
 * session-based (Filament), and no Sanctum bearer can stand in for a browser
 * session — so the hand-off is a ONE-TIME, short-lived token the browser
 * redeems at /auth/broker/{token} (BrokerLoginController).
 *
 * Registered in routes/api.php inside the platform group, guarded by
 * EnsurePlatformKey: its caller is the orchestrator, not a tenant user, and a
 * workspace-pinned Sanctum token cannot exist for a session being established.
 *
 * The token is single-use (`Cache::pull` on redemption) and expires in two
 * minutes, so an intercepted URL is worthless once followed — or shortly after,
 * if it is never followed.
 */
final readonly class WorkspaceSessionController
{
    /** How long a minted hand-off URL stays redeemable. */
    private const int TTL_MINUTES = 2;

    public function __construct(private EnsureWorkspaceOwner $ensureWorkspaceOwner) {}

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            // The tenant's own identity, derived from the signup — never the
            // human's signup email (globally unique; t_9f16c775).
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
        ]);

        $workspace = Workspace::query()->where('slug', $slug)->first();

        if (! $workspace instanceof Workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        // Adopt-or-create the tenant owner. This also repairs a workspace created
        // before the tenant-scoped-owner rule landed: such a workspace is still
        // owned by the shared platform service user, and brokering it would put
        // every tenant into one session.
        $owner = $this->ensureWorkspaceOwner->execute(
            $workspace,
            (string) $validated['owner_email'],
            $validated['owner_name'] ?? null,
        );

        $token = Str::random(64);
        Cache::put(
            "platform:broker:{$token}",
            ['user_id' => $owner->getKey(), 'workspace_id' => $workspace->getKey()],
            now()->addMinutes(self::TTL_MINUTES),
        );

        // A PATH, not an absolute URL: the browser is already on the deployment
        // origin, and the orchestrator refuses an absolute hand-off target so this
        // can never become an open redirect.
        return response()->json([
            'data' => [
                'url' => parse_url(url('/auth/broker/'.$token), PHP_URL_PATH),
                'expires_in' => self::TTL_MINUTES * 60,
            ],
        ]);
    }
}
