<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Opportunity;
use App\Observers\Concerns\TagsFirstCrmData;
use App\Support\LifecycleStage\LifecycleStageProjector;
use Illuminate\Support\Facades\Log;

/**
 * FORK PATCH (t_7273de85): keep the contact's derived `lifecycle_stage` in step
 * with its deals.
 *
 * Two triggers live here because an opportunity can change its contact's answer in
 * two ways that share no column:
 *
 *   · the OPPORTUNITY row changes - created, soft deleted, restored, or moved to
 *     another contact (`saved`). A delete matters as much as a create: the last
 *     open deal disappearing is what turns an `opportunity` back into a `lead`;
 *   · the STAGE value changes, which does NOT touch the opportunity row at all (it
 *     is a `custom_field_values` write). `CustomFieldValueObserver` fires that one,
 *     and it is the path a human's drag-and-drop takes.
 *
 * ── Why the projection cannot break the write it follows ────────────────────
 * The stage is a convenience field; the deal edit is the operator's actual intent.
 * So this observer swallows (and logs) a projection failure rather than letting it
 * roll back the deal: the reconciliation sweep is the guarantee that the field
 * converges, and a CRM that refused a stage move because a derived column was
 * unwritable would be worse than a column that is five minutes stale.
 */
final readonly class OpportunityObserver
{
    use TagsFirstCrmData;

    public function __construct(private LifecycleStageProjector $projector) {}

    public function created(Opportunity $opportunity): void
    {
        $this->tagFirstCrmDataIfNeeded($opportunity);
        $this->project($opportunity);
    }

    public function saved(Opportunity $opportunity): void
    {
        // `created` already projected; a write that touched none of these columns
        // cannot have changed anybody's answer.
        if ($opportunity->wasRecentlyCreated
            || ! $opportunity->wasChanged(['contact_id', 'workspace_id'])) {
            return;
        }

        $previous = $opportunity->wasChanged('contact_id')
            && $opportunity->getOriginal('contact_id') !== null
                ? (string) $opportunity->getOriginal('contact_id')
                : null;

        $this->project($opportunity, $previous);
    }

    public function deleted(Opportunity $opportunity): void
    {
        $this->project($opportunity);
    }

    public function restored(Opportunity $opportunity): void
    {
        $this->project($opportunity);
    }

    public function forceDeleted(Opportunity $opportunity): void
    {
        $this->project($opportunity);
    }

    private function project(Opportunity $opportunity, ?string $previousContactId = null): void
    {
        try {
            $this->projector->projectForOpportunity($opportunity, $previousContactId);
        } catch (\Throwable $exception) {
            Log::warning(
                'lifecycle_stage projection failed for opportunity '
                .$opportunity->getKey().': '.$exception->getMessage(),
            );
        }
    }
}
