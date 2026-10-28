<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\CustomFields\EnsureTagOptionsExist;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Support\ActivityLog\CustomFieldChangeLog;
use App\Support\LifecycleStage\LifecycleStageProjection;
use App\Support\LifecycleStage\LifecycleStageProjector;
use App\Support\Media\UploadClaims;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;

final readonly class CustomFieldValueObserver
{
    public function __construct(
        private EnsureTagOptionsExist $ensureTagOptionsExist,
        private UploadClaims $uploadClaims,
        private CustomFieldChangeLog $changeLog,
        private LifecycleStageProjector $lifecycleStage,
    ) {}

    public function saving(CustomFieldValue $value): void
    {
        $this->uploadClaims->assertClaimable($value);
    }

    public function saved(CustomFieldValue $value): void
    {
        $this->projectLifecycleStage($value);

        if ($value->wasRecentlyCreated || $value->wasChanged(['string_value', 'text_value'])) {
            $this->uploadClaims->sync($value);
        }

        // Only multi-value fields (tags-input et al.) store an array in json_value;
        // scalar-typed fields leave it blank. Short-circuit before loading the
        // customField relation so ordinary custom-field saves incur no extra query.
        if (blank($value->json_value)) {
            return;
        }

        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $this->ensureTagOptionsExist->execute($field, $value->json_value);
    }

    public function created(CustomFieldValue $value): void
    {
        $this->log($value, null);
    }

    public function updated(CustomFieldValue $value): void
    {
        $column = CustomFieldValue::getValueColumn($value->customField->type);

        if (! $value->wasChanged($column)) {
            return;
        }

        $this->log($value, $value->getOriginal($column));
    }

    /**
     * FORK PATCH (t_7273de85): a stage move is a `custom_field_values` write, so it
     * never fires the Opportunity observer - and it is exactly how a human drags a
     * deal to Closed Won inside the CRM. Recomputing the contact here is what makes
     * `lifecycle_stage` follow the pipeline rather than only the platform's own
     * writes.
     *
     * Guarded three ways: only the OPPORTUNITY `stage` field (so the person-side
     * projection this very method triggers cannot recurse), only a write that
     * changed the value, and only when the write can be traced to a contact.
     */
    private function projectLifecycleStage(CustomFieldValue $value): void
    {
        if (LifecycleStageProjection::isRunning()) {
            return;
        }

        if ((string) $value->entity_type !== $this->opportunityEntityType()) {
            return;
        }

        // Resolve the STAGE field by id rather than through the value row's relation:
        // the relation can be orphans-gone, and one indexed lookup per opportunity value
        // write is cheaper than a lazy load that the arch rules (and the guard) would have
        // to be told about anyway.
        $stageFieldId = DB::table('custom_fields')
            ->where('tenant_id', $value->getAttribute('tenant_id'))
            ->where('entity_type', $this->opportunityEntityType())
            ->where('code', 'stage')
            ->value('id');

        if ($stageFieldId === null || (string) $value->custom_field_id !== (string) $stageFieldId) {
            return;
        }

        if (! $value->wasRecentlyCreated && ! $value->wasChanged(['string_value'])) {
            return;
        }

        $opportunity = Opportunity::query()->find($value->entity_id);

        if (! $opportunity instanceof Opportunity) {
            // A soft-deleted or already-gone opportunity: its contact's answer is
            // changed by the delete path, not here.
            return;
        }

        try {
            $this->lifecycleStage->projectForOpportunity($opportunity);
        } catch (\Throwable $exception) {
            // The deal edit outranks the derived column; the reconciliation sweep
            // converges it. Never fail a stage move over a projection.
            Log::warning(
                'lifecycle_stage projection failed for opportunity '
                .$value->entity_id.': '.$exception->getMessage(),
            );
        }
    }

    private function opportunityEntityType(): string
    {
        return Entities::getEntity(Opportunity::class)?->getAlias() ?? 'opportunity';
    }

    private function log(CustomFieldValue $value, mixed $old): void
    {
        $entity = $value->ownRecord();

        if (! $entity instanceof Model) {
            return;
        }

        $this->changeLog->record($entity, $value->customField, $old, $value->getValue());
    }
}
