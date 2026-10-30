<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\DeprovisionWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-level workspace teardown (fork addition, see NOTICE).
 *
 * Registered in routes/api.php OUTSIDE the workspace-scoped group, next to the
 * provisioning route, so it is guarded by EnsurePlatformKey rather than
 * `auth:sanctum` + SetApiWorkspaceContext: its caller is the orchestrator's cancel
 * path, which holds no workspace-pinned token for a tenant it is deleting.
 *
 * Two answers, and they mean different things (t_eec81db2):
 *
 *   200  a workspace carried the slug and is now deleted; `data.deleted` is true and
 *        `data.owner` reports what became of the identity that owned it
 *   404  no workspace carried the slug, so nothing was deleted; `data.deleted` is
 *        false. This is also the answer to a REPEATED teardown, which is why it
 *        stays safe for an idempotent caller.
 *
 * No business logic lives here by design: the write is DeprovisionWorkspace's, which
 * delegates the actual deletion to upstream's DeleteWorkspace action and the
 * identity decision to RetireTenantOwner.
 */
final readonly class WorkspaceDeprovisionController
{
    public function __construct(private DeprovisionWorkspace $deprovisionWorkspace) {}

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $teardown = $this->deprovisionWorkspace->execute($slug);

        if ($teardown === null) {
            return response()->json([
                'message' => 'No workspace exists under the slug "'.$slug.'".',
                'data' => [
                    'slug' => $slug,
                    'deleted' => false,
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'slug' => $slug,
                'deleted' => true,
                'owner' => [
                    'email' => $teardown['owner_email'],
                    'deleted' => $teardown['owner_deleted'],
                    'retained_because' => $teardown['owner_retained_because'],
                ],
            ],
        ]);
    }
}
