<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Jetstream\DeleteWorkspace;
use App\Models\Workspace;

/**
 * Deletes a platform-provisioned workspace by slug.
 *
 * Fork addition — see NOTICE. The teardown counterpart to ProvisionWorkspace: the
 * orchestrator's cancel path removes the tenant by calling this over the same
 * platform-guarded route group.
 *
 * It reuses upstream's own App\Actions\Jetstream\DeleteWorkspace (the DeletesTeams
 * contract), so workspace deletion keeps a single code path — the billing cancel,
 * the `purge()` cascade over the workspace's CRM rows, and the media cleanup are
 * all upstream's business. This class only adds the two things an unattended caller
 * needs: resolving the workspace by slug, and reporting whether there was one.
 *
 * It is IDEMPOTENT: a slug with no workspace returns false rather than throwing, so
 * a retried teardown is a no-op.
 */
final readonly class DeprovisionWorkspace
{
    public function __construct(private DeleteWorkspace $deleteWorkspace) {}

    /**
     * @return bool whether a workspace existed and was deleted
     */
    public function execute(string $slug): bool
    {
        $workspace = Workspace::query()->where('slug', $slug)->first();

        if (! $workspace instanceof Workspace) {
            return false;
        }

        $this->deleteWorkspace->delete($workspace);

        return true;
    }
}
