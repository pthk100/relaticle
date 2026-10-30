<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Jetstream\DeleteUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Ends the tenant identity that owned a workspace, once that workspace is gone
 * (fork addition, see NOTICE).
 *
 * WHY THE IDENTITY IS DELETED AT ALL
 * ----------------------------------
 * A platform-provisioned workspace's owner is not a person: it is the
 * tenant-scoped identity the orchestrator derives from the signup
 * (`admin@<slug>.<base domain>`, see ResolveTenantOwner). It exists to own that
 * one workspace, to be the subject of the workspace-pinned Sanctum credential,
 * and to be the account a brokered or federated sign-in lands in. Once the
 * workspace is gone it has nothing left to do, and it is not an inert row: it
 * holds a password hash, token hashes, two-factor state, passkeys and federated
 * identity links.
 *
 * Two things follow. First, leaving it behind leaves credential material that
 * outlived the tenant it was minted for. Second, `ResolveTenantOwner` is
 * find-or-create BY EMAIL, so a later provision of the same slug and owner
 * address (the platform's own recovery path for a cancelled tenant, or an
 * operator calling POST /api/v1/workspaces by hand) silently ADOPTS the
 * cancelled tenant's row, secrets and all, instead of starting from a clean
 * identity. Deleting on teardown is what makes each provisioning start from a
 * clean identity.
 *
 * WHY IT IS NOT UNCONDITIONAL
 * ---------------------------
 * This runs from a route that addresses any workspace by slug, and the fork has
 * no marker that says "this identity was minted by the platform". So the delete
 * is bounded by three refusals, and every refusal is REPORTED by the caller
 * rather than swallowed:
 *
 *   1. the shared platform service user, which owns every workspace created
 *      without a named owner, is never touched;
 *   2. an owner that is not this tenant's own admin address
 *      (`admin@<slug>.<domain>`) is a human or shared identity, and is kept;
 *   3. an owner that still owns or belongs to another workspace is a shared
 *      identity, and is kept.
 *
 * The predicate fails SAFE: if the address convention ever stops matching, the
 * identity is kept and the teardown response says why. It never deletes the
 * wrong row to keep a promise.
 */
final readonly class RetireTenantOwner
{
    /**
     * The local part of the address the platform derives for a tenant's own
     * identity (`admin@<slug>.<base domain>`, see ResolveTenantOwner). Held as a
     * constant, not inline, so this predicate and its documentation cannot drift.
     */
    private const string TENANT_OWNER_LOCAL_PART = 'admin';

    public function __construct(private DeleteUser $deleteUser) {}

    /**
     * Delete the identity, or explain why it stays.
     *
     * Upstream's own DeleteUser (the DeletesUsers contract) is the deletion path,
     * so tokens, profile photo, memberships and owned workspaces are handled
     * exactly as they are for an account deleted from the panel, and the User
     * model's observer still anonymises the chat rows that outlive a user.
     *
     * @return string|null why the identity was KEPT, or null once its row is gone
     */
    public function execute(User $owner, string $slug): ?string
    {
        $retained = $this->retainedBecause($owner, $slug);

        if ($retained !== null) {
            return $retained;
        }

        $this->deleteUser->delete($owner);

        return null;
    }

    private function retainedBecause(User $owner, string $slug): ?string
    {
        $serviceUser = config('relaticle.platform.service_user_email');

        if (is_string($serviceUser) && $serviceUser !== '' && $owner->email === $serviceUser) {
            return 'it is the shared platform service user, which owns every workspace created without a named owner';
        }

        if (! $this->isTenantAddress($owner, $slug)) {
            return 'it is not this tenant\'s own admin address (admin@<slug>.<domain>), so it is a human or shared identity rather than one the platform minted';
        }

        if ($this->holdsAnotherWorkspace($owner)) {
            return 'it still owns or belongs to another workspace';
        }

        return null;
    }

    /**
     * Is this address the identity the platform derives for THIS tenant?
     *
     * Both halves are checked: the local part is the convention ResolveTenantOwner
     * documents, and the domain is anchored on the workspace's own slug, so an
     * unrelated `admin@` address cannot be mistaken for this tenant's.
     */
    private function isTenantAddress(User $owner, string $slug): bool
    {
        return Str::before($owner->email, '@') === self::TENANT_OWNER_LOCAL_PART
            && str_starts_with(Str::after($owner->email, '@'), $slug.'.');
    }

    private function holdsAnotherWorkspace(User $owner): bool
    {
        return Workspace::query()
            ->withoutGlobalScopes()
            ->where('user_id', $owner->getKey())
            ->exists()
            || $owner->workspaces()->exists();
    }
}
