<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\IssueWorkspaceToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

/**
 * Platform-level credential REISSUE for a workspace that already exists
 * (fork addition, see NOTICE; marketing-platform card t_659436f5).
 *
 * The create seam hands the calling platform the only plaintext the token will
 * ever have; Sanctum stores the SHA-256. So when a workspace's owner moves
 * (`EnsureWorkspaceOwner`, the sole writer of `workspaces.user_id`, driven by the
 * session broker) the stored credential is left signing as an identity that no
 * longer belongs to the workspace, and every workspace-scoped route answers
 * `403 You do not belong to this workspace.`, permanently, because there was no
 * way to mint a replacement for a workspace that already existed.
 *
 * This route is that way. It mints a fresh workspace-pinned token (owner abilities
 * + `workspace_id` pin, through the SAME action the create seam uses) and retires
 * the workspace's earlier platform-minted tokens in the same call, so the caller
 * that stores the new one holds the only live credential for the workspace.
 *
 * The identity is NOT a caller input: the token is always minted for the
 * workspace's owner-of-record. That keeps the invariant the provisioning seam
 * already asserts: a tenant's credential acts as the tenant's own identity, never
 * as an arbitrary account the caller names. Guarded by EnsurePlatformKey (the same
 * shared secret as `POST /api/v1/workspaces`): a workspace-pinned Sanctum token
 * could exist here, but it is exactly the credential this route replaces, so
 * authenticating with it would be circular.
 */
final readonly class WorkspaceTokenController
{
    public function __construct(private IssueWorkspaceToken $issueWorkspaceToken) {}

    public function __invoke(string $slug): JsonResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->first();

        if (! $workspace instanceof Workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        $owner = User::query()->whereKey($workspace->user_id)->first();

        // No owner, or a `workspaces.user_id` that does not resolve to a user who
        // belongs: minting would produce exactly the orphaned credential this route
        // exists to remove. Refuse and say what has to happen first.
        if (! $owner instanceof User || ! $owner->belongsToWorkspace($workspace)) {
            return response()->json([
                'message' => 'This workspace has no owner who belongs to it; adopt it before reissuing its credential.',
            ], 409);
        }

        $issued = $this->issueWorkspaceToken->execute($owner, $workspace);

        return response()->json([
            'data' => [
                'id' => $workspace->getKey(),
                'slug' => $workspace->slug,
                // The identity the credential now acts as. The orchestrator
                // verifies the token against this before storing it.
                'owner_email' => $owner->email,
                'token' => $issued['token'],
                'retired' => $issued['retired'],
            ],
        ], 201);
    }
}
