<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\EnsurePlatformServiceMembership;
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
 * The identity is NOT a caller input, and it is no longer the owner-of-record either
 * (t_ca323f91). A token minted for the workspace's owner is true to that owner but false
 * about the author: every row the platform writes through it carries `creator_id` = a
 * person, so the CRM shows that person's name and a person chip, presenting a projection
 * as a colleague's own edit. The token is minted for the shared service identity
 * (`config('relaticle.platform.service_user_email')`), which is attached to the workspace
 * as a `member` below; the identity-based `⊙ The platform` mark then applies unchanged.
 * It is still never a caller input: no `user_id` or `owner_email` body field is accepted,
 * so a tenant credential can never act as an arbitrary account the caller names. Guarded
 * by EnsurePlatformKey (the same shared secret as `POST /api/v1/workspaces`): a
 * workspace-pinned Sanctum token could exist here, but it is exactly the credential this
 * route replaces, so authenticating with it would be circular.
 */
final readonly class WorkspaceTokenController
{
    public function __construct(
        private IssueWorkspaceToken $issueWorkspaceToken,
        private EnsurePlatformServiceMembership $ensurePlatformServiceMembership,
    ) {}

    public function __invoke(string $slug): JsonResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->first();

        if (! $workspace instanceof Workspace) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }

        // Attaches the identity if this workspace predates the sweep, so the route
        // guarantees the one precondition its token needs (a holder who belongs).
        // Idempotent, and it never removes anybody.
        $actor = $this->ensurePlatformServiceMembership->execute($workspace);

        if (! $actor instanceof User) {
            // Operator misconfiguration (no service user), not caller error: the same 500
            // and the same sentence the provisioning seam answers with.
            return response()->json(['message' => EnsurePlatformServiceMembership::MISSING_IDENTITY], 500);
        }

        $issued = $this->issueWorkspaceToken->execute($actor, $workspace);

        return response()->json([
            'data' => [
                'id' => $workspace->getKey(),
                'slug' => $workspace->slug,
                // The identity the credential now acts as. The orchestrator verifies the
                // token against this before storing it. It is the PLATFORM, not the
                // workspace's owner: `owner_email` used to be reported here and named a
                // different (human) identity than the one the token acted as.
                'actor_email' => $actor->email,
                'token' => $issued['token'],
                'retired' => $issued['retired'],
            ],
        ], 201);
    }
}
