<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\WriteConsentMirror;
use App\Support\ConsentMirror\ConsentMirrorGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE PLATFORM'S CONSENT MIRROR ENDPOINT (fork addition — see NOTICE).
 *
 * `POST /api/v1/platform/consent-mirror`, registered OUTSIDE the workspace-scoped
 * group and guarded by `EnsurePlatformKey` — the same `X-Platform-Key` seam the
 * workspace-provisioning routes use. Its caller is the orchestrator's mirror
 * drain, never a tenant user and never a browser.
 *
 * Why a platform route and not the ordinary People PATCH: the five mirror fields
 * are read-only for every tenant-authenticated caller by design
 * (`ConsentMirrorGuard`). If the platform wrote through the workspace token it
 * would be refused — correctly, since that token is what a human's session and a
 * tenant's own integrations both use.
 *
 * `workspace` and `person` are both named because the platform key is NOT
 * tenant-scoped: the caller states which workspace it is writing into, and the
 * person is looked up INSIDE that workspace only.
 */
final readonly class ConsentMirrorController
{
    public function __construct(private WriteConsentMirror $writeConsentMirror) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
            'person' => ['required', 'string', 'max:64'],
            // Only the five codes; anything else is a 422 from the action, so a
            // caller cannot smuggle an unrelated field through this door.
            'values' => ['required', 'array', 'min:1'],
        ]);

        $stored = $this->writeConsentMirror->execute(
            (string) $validated['workspace'],
            (string) $validated['person'],
            $validated['values'],
        );

        return response()->json([
            'data' => [
                'workspace' => $validated['workspace'],
                'person' => $validated['person'],
                'fields' => $stored,
                // Stated in the payload so a caller (and a reviewer) can see what
                // this route is and is not: the platform store remains the
                // authority, this is a copy of it.
                'authority' => 'platform_consent_store',
                'protected_codes' => ConsentMirrorGuard::protectedCodes(),
            ],
        ]);
    }
}
