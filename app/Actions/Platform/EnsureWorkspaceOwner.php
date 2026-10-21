<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\User;
use App\Models\Workspace;

/**
 * Makes the tenant's own identity the OWNER of a platform-provisioned workspace.
 *
 * Fork addition — see NOTICE.
 *
 * Why this exists: the first cut of the provisioning seam created every workspace
 * under the ONE shared platform service user (`platform@relaticle.invalid`). The
 * tenant therefore had a workspace but no identity of its own — nothing to broker
 * a session AS, and every tenant's workspace owned by the same row. That is the
 * gap the "one signup only" card closes (`t_5df176cd`): the workspace admin must
 * be DERIVED FROM THE SIGNUP, not a shared platform account.
 *
 * Idempotent: re-running adopts the existing owner and membership unchanged, so a
 * retried provisioning step or a later brokered login converges on the same row.
 */
final readonly class EnsureWorkspaceOwner
{
    public function __construct(private ResolveTenantOwner $resolveTenantOwner) {}

    public function execute(Workspace $workspace, string $email, ?string $name = null): User
    {
        $owner = $this->resolveTenantOwner->execute($email, $name);

        // Ownership is `workspaces.user_id` (Workspace::owner()). Moving it off
        // the shared service user is the whole point — a workspace still owned by
        // the service user cannot be brokered into without every tenant sharing
        // one session.
        if ($workspace->user_id !== $owner->getKey()) {
            $workspace->forceFill(['user_id' => $owner->getKey()])->save();
        }

        // Membership so Workspace::allUsers()/hasUser() agree with owner(): the
        // owner is not a member row by default, and the panel resolves access
        // through both.
        if (! $workspace->users()->whereKey($owner->getKey())->exists()) {
            $workspace->users()->attach($owner->getKey(), ['role' => 'admin']);
        }

        return $owner;
    }
}
