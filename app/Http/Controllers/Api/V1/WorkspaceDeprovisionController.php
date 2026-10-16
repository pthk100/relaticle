<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\DeprovisionWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-level workspace teardown (fork addition — see NOTICE).
 *
 * Registered in routes/api.php OUTSIDE the workspace-scoped group, next to the
 * provisioning route, so it is guarded by EnsurePlatformKey rather than
 * `auth:sanctum` + SetApiWorkspaceContext: its caller is the orchestrator's cancel
 * path, which holds no workspace-pinned token for a tenant it is deleting.
 *
 * No business logic lives here by design: the write is DeprovisionWorkspace's, which
 * delegates the actual deletion to upstream's DeleteWorkspace action.
 */
final readonly class WorkspaceDeprovisionController
{
    public function __construct(private DeprovisionWorkspace $deprovisionWorkspace) {}

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $deleted = $this->deprovisionWorkspace->execute($slug);

        return response()->json([
            'data' => [
                'slug' => $slug,
                'deleted' => $deleted,
            ],
        ]);
    }
}
