<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\ProvisionWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Platform-level workspace provisioning (fork addition — see NOTICE).
 *
 * Registered in routes/api.php OUTSIDE the workspace-scoped group, so it is guarded by
 * EnsurePlatformKey rather than `auth:sanctum` + SetApiWorkspaceContext. Its caller is the
 * orchestrator, not a tenant user.
 *
 * No business logic lives here by design: the write is ProvisionWorkspace's, which in turn
 * delegates the actual workspace creation to upstream's CreateWorkspace action.
 */
final readonly class WorkspaceProvisionController
{
    public function __construct(private ProvisionWorkspace $provisionWorkspace) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Format and uniqueness are CreateWorkspace's business (ValidWorkspaceSlug +
            // unique rule), so this endpoint does not duplicate them.
            'slug' => ['nullable', 'string', 'max:255'],
            'token_name' => ['nullable', 'string', 'max:255'],
            // The TENANT-SCOPED owner identity. The orchestrator derives it from
            // the workspace domain (`admin@<slug>.<base domain>`) — never the
            // human's signup email, which is globally unique and would collide on
            // the second tenant the same human signs up for (t_9f16c775).
            'owner_email' => ['nullable', 'email', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->provisionWorkspace->execute(
                (string) $validated['name'],
                $validated['slug'] ?? null,
                $validated['token_name'] ?? null,
                $validated['owner_email'] ?? null,
                $validated['owner_name'] ?? null,
            );
        } catch (RuntimeException $exception) {
            // Operator misconfiguration (no service user), not caller error.
            return response()->json(['message' => $exception->getMessage()], 500);
        }

        return response()->json([
            'data' => [
                'id' => $result['workspace']->getKey(),
                'name' => $result['workspace']->name,
                'slug' => $result['workspace']->slug,
                'token' => $result['token'],
                'owner_email' => $result['owner']->email,
                'owner_user_id' => $result['owner']->getKey(),
            ],
        ], 201);
    }
}
