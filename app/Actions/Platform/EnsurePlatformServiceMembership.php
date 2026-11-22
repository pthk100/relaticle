<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;

/**
 * Makes the configured platform identity a MEMBER of a workspace (fork addition; see NOTICE;
 * marketing-platform card t_ca323f91).
 *
 * Why this exists: the credential the platform signs its API calls with must act as the
 * platform, not as the workspace's tenant owner. `WorkspaceTokenController` mints for this
 * identity, and `SetApiWorkspaceContext` requires the tokenable user to BELONG to the workspace
 * it is pinned to — so the identity has to be a member before its token is usable.
 *
 * Role `member`, deliberately: `IssueWorkspaceToken` always mints the owner ability set
 * (`WorkspaceCapability::forOwner()`), and the API's authorization is that token's abilities
 * plus the policies' record capabilities, so the attached role governs only panel UI. An
 * admin row here would also appear in member-management actions a human might take.
 *
 * Idempotent, like `EnsureWorkspaceOwner`: re-running attaches nothing and creates no user.
 * A workspace still OWNED by the service identity (the first provisioning cut did that) needs
 * no row — `Workspace::allUsers()` merges the owner, and `belongsToWorkspace()` counts
 * ownership.
 */
final readonly class EnsurePlatformServiceMembership
{
    /**
     * Attach the platform identity and return it, so a caller that minted a credential for
     * "the platform" uses exactly the account this method resolved.
     */
    public function execute(Workspace $workspace): User
    {
        $user = $this->serviceUser();

        $this->attach($workspace, $user);

        return $user;
    }

    /**
     * The same attach, for a seam that must not fail when the deployment has no platform
     * identity configured (`null` then).
     *
     * Adoption is that seam: a brokered sign-in must never be refused because the
     * automation account is absent — the person locked out of their CRM is exactly the
     * defect the broker exists to remove. A required identity is refused by the MINT
     * (`execute()` above), which is where a missing one makes the credential impossible
     * rather than merely unattached.
     */
    public function executeIfAvailable(Workspace $workspace): ?User
    {
        $user = $this->serviceUserOrNull();

        if (! $user instanceof User) {
            return null;
        }

        $this->attach($workspace, $user);

        return $user;
    }

    private function attach(Workspace $workspace, User $user): void
    {
        // The owner is not a member row by default and `allUsers()` merges it, so a
        // workspace still OWNED by the service identity (the first provisioning cut did
        // that) needs nothing.
        if ($workspace->user_id === $user->getKey()) {
            return;
        }

        if (! $workspace->users()->whereKey($user->getKey())->exists()) {
            $workspace->users()->attach($user->getKey(), ['role' => WorkspaceRole::Member->value]);
        }
    }

    /**
     * The identity the platform signs as, seeded by `php artisan platform:service-user`.
     *
     * Refuses rather than creating one on the fly: a route that silently invented an identity
     * would let a platform-key caller mint accounts, and the account's password/name policy is
     * the command's business, not this action's.
     */
    public function serviceUser(): User
    {
        $user = $this->serviceUserOrNull();

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Platform service user is not configured or does not exist. Run `php artisan platform:service-user`.'
            );
        }

        return $user;
    }

    /** The configured identity, or `null` when this deployment has not seeded one. */
    private function serviceUserOrNull(): ?User
    {
        $email = config('relaticle.platform.service_user_email');

        return is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : null;
    }
}
