<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Jetstream\CreateWorkspace;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * Creates a workspace for the platform and returns a Sanctum token pinned to it.
 *
 * Fork addition — see NOTICE. It reuses upstream's own App\Actions\Jetstream\CreateWorkspace,
 * which is the same action the Filament registration page resolves, so workspace creation
 * keeps exactly one code path: validation, `Plan::default()`, the first-workspace flag and
 * the Pro-trial side effect all stay upstream's business. This class only adds the two
 * things an unattended caller needs — choosing the owning identity, and handing back a
 * credential.
 *
 * It runs outside the workspace-scoped API group in routes/api.php, because a token has to
 * be pinned to a workspace (see SetApiWorkspaceContext) and so cannot exist until this has.
 */
final readonly class ProvisionWorkspace
{
    public function __construct(
        private CreateWorkspace $createWorkspace,
        private ResolveTenantOwner $resolveTenantOwner,
    ) {}

    /**
     * @return array{workspace: Workspace, owner: User, token: string}
     */
    public function execute(
        string $name,
        ?string $slug = null,
        ?string $tokenName = null,
        ?string $ownerEmail = null,
        ?string $ownerName = null,
    ): array {
        // The workspace's owner is the TENANT'S OWN identity when the caller names
        // one (the orchestrator derives `admin@<slug>.<base domain>` from the
        // signup). Only a caller that names no owner falls back to the shared
        // platform service user — which is what the very first cut did for every
        // tenant, leaving each workspace with no identity of its own to broker a
        // session as (t_5df176cd).
        $user = $ownerEmail !== null && $ownerEmail !== ''
            ? $this->resolveTenantOwner->execute($ownerEmail, $ownerName)
            : $this->serviceUser();

        $workspace = DB::transaction(
            fn (): Workspace => $this->createWorkspace->create($user, [
                'name' => $name,
                'slug' => $slug,
            ])
        );

        // Upstream's panel drops a brand-new workspace into its own first-run
        // onboarding wizard (App\Filament\Pages\SetupWorkspace) while
        // `onboarding_step` is non-null. A PLATFORM-provisioned tenant has
        // already told us who it is — name, domain, plan — at signup, so making
        // the brand re-enter that in the CRM is the same "register again" friction
        // the console broker exists to remove (t_5df176cd). `null` is upstream's
        // own "onboarding complete" marker.
        $workspace->forceFill(['onboarding_step' => null])->save();

        return [
            'workspace' => $workspace,
            'owner' => $user,
            'token' => $this->mintWorkspaceToken($user, $workspace, $tokenName),
        ];
    }

    /**
     * The identity that owns platform-provisioned workspaces, seeded by
     * `php artisan platform:service-user`.
     *
     * This refuses rather than creating a user on the fly: a route that silently invents an
     * owner would make an unauthenticated-adjacent caller able to mint identities.
     */
    private function serviceUser(): User
    {
        $email = config('relaticle.platform.service_user_email');

        $user = is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : null;

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Platform service user is not configured or does not exist. Run `php artisan platform:service-user`.'
            );
        }

        return $user;
    }

    /**
     * Mirrors App\Livewire\App\AccessTokens\CreateAccessToken: Sanctum's createToken() accepts
     * no extra attributes, so workspace_id is filled in immediately after creation. A pinned
     * workspace_id is what SetApiWorkspaceContext resolves on every later API call, which is
     * why the token is minted here rather than left for the caller to manage.
     */
    private function mintWorkspaceToken(User $user, Workspace $workspace, ?string $tokenName): string
    {
        $abilities = WorkspaceCapability::tokenPermissions(WorkspaceCapability::forOwner());

        /** @var NewAccessToken $token */
        $token = $user->createToken($tokenName ?? "platform:{$workspace->slug}", $abilities);

        $token->accessToken->fill(['workspace_id' => $workspace->getKey()])->save();

        // Sanctum returns "<id>|<plaintext>"; only the secret half is useful to a caller.
        return explode('|', $token->plainTextToken, 2)[1];
    }
}
