<?php

declare(strict_types=1);

namespace App\Support\LifecycleStage;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomFieldValue as CustomFieldValueModel;
use App\Models\Opportunity;
use App\Models\People;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;

/**
 * READS a contact's opportunities and WRITES the derived `lifecycle_stage`.
 *
 * This is the only writer of the field. It does the two halves the card's
 * mechanism needs:
 *
 *   `projectPerson()`       one contact, now - called by the observers, so a stage
 *                           change made ANYWHERE (the CRM's drag-and-drop, the REST
 *                           API, the MCP tools, an import) lands on the contact
 *                           inside the same write;
 *   `stageNamesByContact()` every contact of a workspace in one query - the read
 *                           the reconciliation sweep folds over.
 *
 * ── Why the read joins by value row, not by a relation ──────────────────────
 * `stage` is an OPPORTUNITY CUSTOM FIELD, so its value lives in
 * `custom_field_values.string_value` as the OPTION ID (single-choice fields store
 * the option key, not the label). The label a person recognises - `Closed Won` -
 * is one join away in `custom_field_options.name`, and that join is the only place
 * the two spellings meet. The same shape `OpportunityAggregatesQuery` uses.
 *
 * ── What is NOT here ────────────────────────────────────────────────────────
 * No rule. `LifecycleStage::fromOpportunityStages()` is the whole of it, and this
 * class only feeds it and stores the answer.
 */
final readonly class LifecycleStageProjector
{
    /**
     * Bound where the workspace has no `stage` field: an id no option row can
     * carry, so the join yields "no stage" for every opportunity - which the rule
     * reads as OPEN. That is the honest answer: nothing says the deal is closed, so
     * it is not closed.
     */
    private const string NO_STAGE_FIELD = '';

    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    /**
     * Recompute one contact's stage, in its own workspace context.
     *
     * Returns the value the contact now holds, or null when the workspace has no
     * `lifecycle_stage` field (a workspace that predates the field set): there is
     * nothing to write and writing nothing is the correct answer, not an error.
     */
    public function projectPerson(string $workspaceId, string $personId): ?string
    {
        return $this->currentWorkspace->within($workspaceId, function () use ($workspaceId, $personId): ?string {
            $field = $this->peopleField($workspaceId);

            if ($field === null) {
                return null;
            }

            $person = People::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($personId)
                ->first();

            if (! $person instanceof People) {
                return null;
            }

            $target = LifecycleStage::fromOpportunityStages(
                $this->stageNamesForContact($workspaceId, (string) $person->getKey()),
            );

            $this->store($person, $target);

            return $this->storedStage($person->fresh() ?? $person, $field);
        });
    }

    /**
     * Recompute the contact an opportunity belongs to - the observer's entry point.
     *
     * `$previousContactId` matters: moving a deal from one person to another must
     * recompute BOTH, or the first person keeps a `customer` stage for a deal that
     * is no longer theirs.
     */
    public function projectForOpportunity(Opportunity $opportunity, ?string $previousContactId = null): void
    {
        $workspaceId = (string) $opportunity->workspace_id;
        $current = $opportunity->contact_id === null ? null : (string) $opportunity->contact_id;

        foreach (array_unique(array_filter([$current, $previousContactId])) as $personId) {
            $this->projectPerson($workspaceId, (string) $personId);
        }
    }

    /**
     * Every live opportunity's stage name, grouped by the contact it belongs to.
     *
     * One query for the whole workspace: the sweep folds this map instead of
     * asking per person, which is what keeps a nightly full pass off the CRM's
     * 600 req/min budget (nothing here is a request per person).
     *
     * @return array<string, list<string|null>> contact id => stage names
     */
    public function stageNamesByContact(string $workspaceId): array
    {
        /** @var list<object{contact_id: string, stage_name: string|null}> $rows */
        $rows = DB::select(
            'SELECT o.contact_id AS contact_id, opt.name AS stage_name
               FROM opportunities o
               LEFT JOIN custom_field_values v
                      ON v.entity_id = o.id AND v.entity_type = ? AND v.custom_field_id = ?
               LEFT JOIN custom_field_options opt
                      ON opt.custom_field_id = v.custom_field_id
                     AND (opt.id = v.string_value OR opt.name = v.string_value)
              WHERE o.workspace_id = ? AND o.contact_id IS NOT NULL AND o.deleted_at IS NULL',
            [$this->opportunityEntityType(), $this->stageFieldId($workspaceId) ?? self::NO_STAGE_FIELD, $workspaceId],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->contact_id][] = $row->stage_name === null ? null : (string) $row->stage_name;
        }

        return $map;
    }

    /**
     * The stage of every live opportunity one contact has. `[null]`-style entries
     * are opportunities with no stage: open, not closed.
     *
     * @return list<string|null>
     */
    public function stageNamesForContact(string $workspaceId, string $personId): array
    {
        /** @var list<object{stage_name: string|null}> $rows */
        $rows = DB::select(
            'SELECT opt.name AS stage_name
               FROM opportunities o
               LEFT JOIN custom_field_values v
                      ON v.entity_id = o.id AND v.entity_type = ? AND v.custom_field_id = ?
               LEFT JOIN custom_field_options opt
                      ON opt.custom_field_id = v.custom_field_id
                     AND (opt.id = v.string_value OR opt.name = v.string_value)
              WHERE o.workspace_id = ? AND o.contact_id = ? AND o.deleted_at IS NULL',
            [$this->opportunityEntityType(), $this->stageFieldId($workspaceId) ?? self::NO_STAGE_FIELD, $workspaceId, $personId],
        );

        return array_map(
            static fn (object $row): ?string => $row->stage_name === null ? null : (string) $row->stage_name,
            $rows,
        );
    }

    /**
     * Write the derived value, and only when it differs.
     *
     * ── Why this is a ONE-ROW write, not a model update ─────────────────────────
     * `saveCustomFields()` writes a row for every code the payload omits, and the
     * payload it accepts is the whole contact. Writing the derived stage through it
     * therefore does two things this field must never do:
     *
     *   · it inserts NULL rows for the other twenty-five codes, and the caller that is
     *     still finishing its own save then inserts the REAL row for one of them
     *     (`emails`) and hits `custom_field_values_entity_type_unique`. Measured: the
     *     contact upsert that matches on that email 500s, so every brand contact that
     *     arrives twice becomes a duplicate or an error;
     *   · it goes through `UsesCustomFields`'s STATIC, `spl_object_id`-keyed payload
     *     stash, which replaces whatever payload the model it is writing was already
     *     holding.
     *
     * So the platform writes exactly the one row it owns: the `custom_field_values`
     * row for `lifecycle_stage`, upserted on the same four-column key the table's
     * unique index uses. Nothing else on the contact is touched, and no model event
     * fires, which is also why a derived change never appears in the contact's
     * activity timeline as if a human had made it.
     *
     * The value is the OPTION ID, the shape every other write path produces: a bare
     * label would store `customer` where the UI looks up an id, reading as an empty
     * field with a value in the database.
     *
     * "Only when it differs" is not an optimisation: the sweep runs over every contact
     * of every workspace, and rewriting rows that already hold the right value would
     * turn a nightly correction into a write storm.
     */
    public function store(People $person, string $target): bool
    {
        // A projection inside a projection is a loop, not a correction.
        if (LifecycleStageProjection::isRunning()) {
            return false;
        }

        $workspaceId = (string) $person->workspace_id;
        $field = $this->peopleField($workspaceId);

        if ($field === null) {
            return false;
        }

        if ($this->storedStage($person, $field) === $target) {
            return false;
        }

        $optionId = $this->optionIdFor($field, $target);

        if ($optionId === null) {
            // The workspace does not offer this value: its `lifecycle_stage` options were
            // curated away from the four the derivation needs. Nothing to write, and the
            // option ids it does offer are not ours to guess.
            return false;
        }

        $where = [
            'tenant_id' => $workspaceId,
            'entity_type' => 'people',
            'entity_id' => (string) $person->getKey(),
            'custom_field_id' => $field['id'],
        ];

        LifecycleStageProjection::run(fn () => $this->writeRow($where, $optionId));

        return true;
    }

    /**
     * Insert the value row, or update the one that is already there.
     *
     * The four columns are the table's own unique key, so this is an upsert in the only
     * sense the table allows; the ULID is generated here because the row is inserted with
     * the query builder (no model, no events).
     *
     * @param  array<string, string>  $where
     */
    private function writeRow(array $where, string $optionId): void
    {
        $existing = DB::table('custom_field_values')->where($where)->value('id');

        if (is_string($existing)) {
            DB::table('custom_field_values')->where('id', $existing)->update(['string_value' => $optionId]);

            return;
        }

        DB::table('custom_field_values')->insert($where + [
            'id' => (new CustomFieldValueModel)->newUniqueId(),
            'string_value' => $optionId,
        ]);
    }

    /** What the contact holds for `lifecycle_stage` right now, as an option name. */
    /**
     * @param  array{id: string, type: string, entity_type: string}|null  $field
     */
    public function storedStage(People $person, ?array $field = null): ?string
    {
        $field ??= $this->peopleField((string) $person->workspace_id);

        if ($field === null) {
            return null;
        }

        $person->loadMissing('customFieldValues');

        $row = $person->getRelation('customFieldValues')
            ->first(fn ($value): bool => (string) $value->custom_field_id === $field['id']);

        if ($row === null) {
            return null;
        }

        // Read the two columns a single-choice field can land in, rather than asking the
        // package which one this install uses (`getValueColumn` is a custom-fields model
        // call, and the app's arch rules keep the app off those models). `string_value`
        // is the one this deployment writes, verified against the live workspace; the
        // integer fallback covers the package's other option-key mode, and a wrong
        // choice there would read as a silent null that looks exactly like "the field
        // was never written".
        $stored = $row->string_value ?? $row->integer_value;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        // A select's value is the option ID; the answer a caller compares is the option
        // NAME. A value that is already a name (an older write, or a workspace whose
        // options were replaced) resolves to itself.
        $name = DB::table('custom_field_options')
            ->where('custom_field_id', $field['id'])
            ->where(fn ($query) => $query->where('id', $stored)->orWhere('name', $stored))
            ->value('name');

        return is_string($name) ? $name : $stored;
    }

    /**
     * The workspace's `lifecycle_stage` field row, or null when it never received the
     * field set.
     *
     * Read through the query builder, not the `CustomField` model: this method is
     * called from inside write paths (an observer, a sweep) where the package's model
     * scopes and casts are one more thing that can answer a different question than the
     * row does. `id`, `type` and `entity_type` are all this class needs.
     *
     * @return array{id: string, type: string, entity_type: string}|null
     */
    public function peopleField(string $workspaceId): ?array
    {
        $row = DB::table('custom_fields')
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'people')
            ->where('code', PeopleField::LIFECYCLE_STAGE->value)
            ->first(['id', 'type', 'entity_type']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'type' => (string) $row->type,
            'entity_type' => (string) $row->entity_type,
        ];
    }

    /**
     * The option ID a label means in this workspace, or null when it offers no such value.
     *
     * @param  array{id: string, type: string, entity_type: string}  $field
     */
    private function optionIdFor(array $field, string $label): ?string
    {
        $id = DB::table('custom_field_options')
            ->where('custom_field_id', $field['id'])
            ->where('name', $label)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function stageFieldId(string $workspaceId): ?string
    {
        $id = DB::table('custom_fields')
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $this->opportunityEntityType())
            ->where('code', 'stage')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /** The entity alias the custom fields package uses for opportunities. */
    private function opportunityEntityType(): string
    {
        return Entities::getEntity(Opportunity::class)?->getAlias() ?? 'opportunity';
    }
}
