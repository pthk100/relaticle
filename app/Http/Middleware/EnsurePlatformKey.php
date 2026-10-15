<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-level authentication for the provisioning surface (fork addition — see NOTICE).
 *
 * Deliberately NOT `auth:sanctum`. The whole purpose of the route this guards is to create
 * the workspace that a Sanctum token must be pinned to, so no token can exist yet. It
 * authenticates the calling *platform* (shared secret) rather than a tenant user.
 */
final readonly class EnsurePlatformKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('relaticle.platform.api_key');

        // Fail closed. An unset or empty key must refuse every caller: treating a missing
        // secret as "no auth configured, allow" would leave an unauthenticated route that
        // creates workspaces and mints tokens.
        if (! is_string($expected) || $expected === '') {
            return response()->json(['message' => 'Platform provisioning is not configured.'], 503);
        }

        $presented = $request->header('X-Platform-Key');

        // Constant-time comparison so the header cannot be recovered by timing.
        if (! is_string($presented) || ! hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Invalid platform key.'], 401);
        }

        return $next($request);
    }
}
