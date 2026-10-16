<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class WorkspacePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace);
    }

    /**
     * Determine whether the user can create models.
     *
     * The platform service user — the single identity that owns every workspace
     * created through POST /api/v1/workspaces (see
     * App\Actions\Platform\ProvisionWorkspace) — is EXEMPT from the per-user cap.
     * That cap is an anti-abuse limit for human owners; this one non-human
     * identity owns a workspace per tenant, so applying it here would silently
     * cap the whole platform at max_owned_per_user tenants. Fork addition — see
     * NOTICE.
     */
    public function create(User $user): bool
    {
        if ($this->isPlatformServiceUser($user)) {
            return true;
        }

        return $user->ownedWorkspaces()->count() < (int) config('relaticle.workspaces.max_owned_per_user');
    }

    /**
     * Whether this user is the platform's provisioning identity, which owns
     * workspaces on behalf of tenants rather than on its own behalf.
     */
    private function isPlatformServiceUser(User $user): bool
    {
        $email = config('relaticle.platform.service_user_email');

        return is_string($email) && $email !== '' && $user->email === $email;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::MembersManage);
    }

    /**
     * Granting or revoking Admin is the owner's alone, so an Admin cannot
     * escalate a peer or themselves.
     */
    public function promoteToAdmin(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::MembersPromoteAdmin);
    }

    /**
     * Determine whether the user can add workspace members.
     */
    public function addWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can update workspace member permissions.
     */
    public function updateWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can remove workspace members.
     */
    public function removeWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage);
    }

    public function deleteAny(): bool
    {
        return false;
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage);
    }

    public function restoreAny(): bool
    {
        return false;
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::WorkspaceManage);
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }
}
