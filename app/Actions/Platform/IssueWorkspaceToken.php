<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\WorkspaceCapability;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mints the workspace-pinned Sanctum token the platform signs its API calls with,
 * retiring the ones it replaces.
 *
 * Fork addition, see NOTICE.
 *
 * Extracted from `ProvisionWorkspace::mintWorkspaceToken` so there is exactly ONE
 * mint implementation: the create seam (`POST /api/v1/workspaces`) and the reissue
 * seam (`POST /api/v1/workspaces/{slug}/token`) both go through it, and both keep
 * the two properties `SetApiWorkspaceContext` depends on: the owner ability set,
 * and a `workspace_id` pin the tokenable user actually belongs to
 * (`PersonalAccessToken::booted`).
 *
 * WHY A REISSUE SEAM EXISTS (marketing-platform card t_659436f5). The plaintext
 * token lives with the CALLING platform (Sanctum keeps only its SHA-256), so an
 * ownership change cannot carry the credential with it. A workspace adopted onto a
 * new owner (`EnsureWorkspaceOwner`, the ONLY writer of `workspaces.user_id`)
 * therefore leaves the stored token signing as an identity that neither owns nor
 * belongs to the workspace: `SetApiWorkspaceContext` answers
 * `403 {"message":"You do not belong to this workspace."}` on every
 * workspace-scoped route, forever, with nothing noticing. Before this action the
 * fork could not mint a credential for a workspace that already existed, so such a
 * tenant's CRM was unrecoverable over the API seam.
 *
 * Retiring is part of the same operation, not a separate one: a fresh workspace
 * has no previous token (so the create seam retires zero), and a reissue must
 * leave the caller holding the only live credential for that workspace.
 */
final readonly class IssueWorkspaceToken
{
    /**
     * Mint the workspace's platform credential, retire the earlier ones.
     *
     * The replacement is minted FIRST and the superseded rows are deleted after,
     * deliberately: retiring first would leave the calling platform with no
     * credential at all if the mint failed, while minting first makes the worst
     * case an unreferenced extra row that the next rotation retires.
     *
     * Only rows this seam mints are touched: pinned to this workspace, and named
     * `platform:<slug>` (the name every token created here carries).
     *
     * Mirrors `App\Livewire\App\AccessTokens\CreateAccessToken`: Sanctum's
     * `createToken()` accepts no extra attributes, so `workspace_id` is filled in
     * immediately after creation.
     *
     * @return array{token: string, retired: int}
     */
    public function execute(User $user, Workspace $workspace, ?string $tokenName = null): array
    {
        $name = $tokenName ?? $this->tokenName($workspace);

        $abilities = WorkspaceCapability::tokenPermissions(WorkspaceCapability::forOwner());

        /** @var NewAccessToken $minted */
        $minted = $user->createToken($name, $abilities);
        $minted->accessToken->fill(['workspace_id' => $workspace->getKey()])->save();

        $retired = PersonalAccessToken::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('name', $name)
            ->whereKeyNot($minted->accessToken->getKey())
            ->delete();

        // Sanctum returns "<id>|<plaintext>"; only the secret half is useful.
        return [
            'token' => explode('|', $minted->plainTextToken, 2)[1],
            'retired' => $retired,
        ];
    }

    /**
     * The name every platform-minted token carries, per workspace. It is what makes
     * "the credential this seam issued for THIS workspace" a queryable set, which is
     * how a rotation knows what to retire.
     */
    private function tokenName(Workspace $workspace): string
    {
        return "platform:{$workspace->slug}";
    }
}
