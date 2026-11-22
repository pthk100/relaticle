<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Makes the configured platform identity a MEMBER of a workspace (fork addition; see NOTICE;
 * marketing-platform card t_ca323f91).
 *
 * Why this exists: the credential the platform signs its API calls with must act as the
 * platform, not as the workspace's tenant owner. `WorkspaceTokenController` mints for this
 * identity, and `SetApiWorkspaceContext` requires the tokenable user to BELONG to the workspace
 * it is pinned to, so the identity has to be a member before its token is usable.
 *
 * Role `member`, deliberately: `IssueWorkspaceToken` always mints the owner ability set
 * (`WorkspaceCapability::forOwner()`), and the API's authorization is that token's abilities
 * plus the policies' record capabilities, so the attached role governs only panel UI. An
 * admin row here would also appear in member-management actions a human might take.
 *
 * Idempotent, like `EnsureWorkspaceOwner`: re-running attaches nothing and creates no user.
 * A workspace still OWNED by the service identity (the first provisioning cut did that) needs
 * no row: `Workspace::allUsers()` merges the owner, and `belongsToWorkspace()` counts ownership.
 */
final readonly class EnsurePlatformServiceMembership
{
    /**
     * What a caller must say when this deployment has no platform identity. One sentence, in
     * one place, because two seams refuse on it: the mint (there is nothing to sign as) and
     * provisioning (there is nothing to attach).
     */
    public const string MISSING_IDENTITY = 'Platform service user is not configured or does not exist. Run `php artisan platform:service-user`.';

    /**
     * Attach the platform identity and return it, so a caller that minted a credential for
     * "the platform" uses exactly the account this method resolved.
     *
     * `null` when this deployment has no configured identity, or has one that was never
     * seeded. That is not an error HERE, because adoption calls this too and a brokered
     * sign-in must never be refused because the automation account is absent (the person
     * locked out of their CRM is exactly the defect the broker exists to remove). The seams
     * that CANNOT work without it refuse instead, naming `MISSING_IDENTITY`.
     */
    public function execute(Workspace $workspace): ?User
    {
        $email = config('relaticle.platform.service_user_email');

        $user = is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : null;

        if (! $user instanceof User) {
            return null;
        }

        // An owner is not a member row by default and `allUsers()` merges it, so a
        // workspace still OWNED by the service identity needs no row of its own.
        if ($workspace->user_id === $user->getKey()) {
            return $user;
        }

        if (! $workspace->users()->whereKey($user->getKey())->exists()) {
            $workspace->users()->attach($user->getKey(), ['role' => WorkspaceRole::Member->value]);
        }

        return $user;
    }
}
