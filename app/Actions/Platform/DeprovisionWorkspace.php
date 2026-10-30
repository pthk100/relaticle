<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Jetstream\DeleteWorkspace;
use App\Models\User;
use App\Models\Workspace;

/**
 * Deletes a platform-provisioned workspace by slug, and ends the tenant identity
 * that owned it.
 *
 * Fork addition, see NOTICE. The teardown counterpart to ProvisionWorkspace: the
 * orchestrator's cancel path removes the tenant by calling this over the same
 * platform-guarded route group.
 *
 * It reuses upstream's own App\Actions\Jetstream\DeleteWorkspace (the DeletesTeams
 * contract), so workspace deletion keeps a single code path: the billing cancel,
 * the `purge()` cascade over the workspace's CRM rows, and the media cleanup are
 * all upstream's business. This class adds what an unattended caller needs:
 * resolving the workspace by slug, capturing the owner BEFORE the workspace goes,
 * handing that identity to RetireTenantOwner (which owns the decision on whether
 * it survives), and reporting both outcomes.
 *
 * The two outcomes are distinguishable, which is the point (t_eec81db2): a slug
 * with no workspace yields NO outcome and the route answers 404, while a DELETE
 * that removed a workspace answers 200. Repeating the call is still harmless (the
 * second call is the 404, which an idempotent caller reads as "already gone"), but
 * "deleted it" and "there was nothing to delete" are no longer the same answer.
 */
final readonly class DeprovisionWorkspace
{
    public function __construct(
        private DeleteWorkspace $deleteWorkspace,
        private RetireTenantOwner $retireTenantOwner,
    ) {}

    /**
     * Every outcome except "there was no such workspace", which is null and is
     * what the route turns into its 404.
     *
     * @return array{owner_email: string|null, owner_deleted: bool, owner_retained_because: string|null}|null
     */
    public function execute(string $slug): ?array
    {
        // `owner` is eager-loaded rather than read off the model after the delete:
        // the relation is needed before it, and lazy loading is prevented outside
        // production.
        $workspace = Workspace::query()->with('owner')->where('slug', $slug)->first();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        $owner = $workspace->owner;

        $this->deleteWorkspace->delete($workspace);

        if (! $owner instanceof User) {
            return [
                'owner_email' => null,
                'owner_deleted' => false,
                'owner_retained_because' => 'the workspace had no owner row to end',
            ];
        }

        $retained = $this->retireTenantOwner->execute($owner, $slug);

        return [
            'owner_email' => $owner->email,
            'owner_deleted' => $retained === null,
            'owner_retained_because' => $retained,
        ];
    }
}
