<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\People;
use App\Observers\Concerns\TagsFirstCrmData;
use App\Support\LifecycleStage\LifecycleStageProjector;
use Illuminate\Support\Facades\Log;

/**
 * FORK PATCH (t_7273de85): a new contact is a `Lead` the moment it exists.
 *
 * Without this, a contact created between two reconciliation ticks carries NO
 * `lifecycle_stage` at all, and an empty select reads as "unknown", not as "not in
 * the pipeline". The projection writes `lead` here - which is exactly what the
 * derivation says for a contact with no deals - so the field is true from birth and
 * the sweep only has to keep it true.
 *
 * Same failure rule as the opportunity observer: a projection that throws must not
 * fail the contact write, because the sweep is the guarantee that the column
 * converges and the record is the thing a person was actually creating.
 */
final readonly class PeopleObserver
{
    use TagsFirstCrmData;

    public function __construct(private LifecycleStageProjector $projector) {}

    public function created(People $people): void
    {
        $this->tagFirstCrmDataIfNeeded($people);

        try {
            $this->projector->projectPerson((string) $people->workspace_id, (string) $people->getKey());
        } catch (\Throwable $exception) {
            Log::warning(
                'lifecycle_stage projection failed for person '
                .$people->getKey().': '.$exception->getMessage(),
            );
        }
    }
}
