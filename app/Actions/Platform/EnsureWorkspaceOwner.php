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

        // A workspace adopted for a PLATFORM tenant skips the panel's own
        // first-run onboarding wizard: the brand has already described itself at
        // signup, and re-entering that in the CRM is the "register again" friction
        // this seam exists to remove (t_5df176cd). `null` is upstream's
        // "onboarding complete" marker (App\Filament\Pages\SetupWorkspace).
        if ($workspace->onboarding_step !== null) {
            $workspace->forceFill(['onboarding_step' => null])->save();
        }

        return $owner;
    }
}
