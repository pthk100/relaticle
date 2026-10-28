<?php

declare(strict_types=1);

namespace App\Support\LifecycleStage;

use App\Enums\CustomFields\PeopleField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;

/**
 * THE RECONCILIATION PASS for `lifecycle_stage` (t_7273de85).
 *
 * ── Why an observer is not enough ───────────────────────────────────────────
 * The observers (`OpportunityObserver`, `CustomFieldValueObserver`) recompute the
 * contact inside the write, so an ordinary stage change is immediate. They cannot
 * cover everything: a value row written straight into Postgres, a restored backup,
 * a row queued before this field existed, a workspace that received the field
 * after its deals were already closed, or a projection that failed while the CRM
 * was mid-rollout. The sweep is what makes the field TRUE rather than fresh.
 *
 * ── Two modes, and the cursor each one uses ─────────────────────────────────
 * INCREMENTAL (`--incremental`, the schedule's five-minute tick) reads two legs
 * per workspace, both keyed on a timestamp cursor kept in
 * `platform_reconcile_cursors` under the name `lifecycle_stage`:
 *
 *   a) `opportunities.updated_at >= cursor` - creation, contact change, soft
 *      delete. A stage change does NOT touch the opportunity row, which is the
 *      trap: the value lives in `custom_field_values`, so
 *   b) `custom_field_values.updated_at >= cursor` for the workspace's `stage`
 *      field is the leg that sees a human drag a deal to Closed Won.
 *
 * The window starts `OVERLAP_SECONDS` BEFORE the cursor. That overlap is the
 * correctness margin: a row committed while the previous pass was reading still
 * has an `updated_at` inside it, and a clock that disagrees with ours by a few
 * seconds then costs one extra recomputation instead of one missed deal.
 *
 * FULL (`--full`, nightly) ignores the cursor and recomputes every contact in
 * every workspace. It is the answer to everything the cursor cannot see (hard
 * deletes, a stage value row deleted outright, a person created before the field).
 *
 * ── Idempotent by construction ──────────────────────────────────────────────
 * The projector writes only when the stored value differs, so running either mode
 * twice writes nothing the second time (asserted in the feature test).
 */
final readonly class LifecycleStageReconciler
{
    /** The cursor's name in `platform_reconcile_cursors`. */
    public const string CURSOR_NAME = 'lifecycle_stage';

    /**
     * How far back of the cursor each incremental pass re-reads. Ten minutes is
     * two schedule ticks: enough to absorb commit/clock skew, cheap enough that a
     * tenant with a busy pipeline re-reads a handful of deals, not its history.
     */
    public const int OVERLAP_SECONDS = 600;

    public function __construct(
        private LifecycleStageProjector $projector,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @return array{mode: string, workspaces: int, scanned: int, changed: int, cursor: string|null}
     */
    public function full(int $chunk = 500): array
    {
        $workspaces = 0;
        $scanned = 0;
        $changed = 0;
        $highWater = null;

        foreach ($this->workspaceIds() as $workspaceId) {
            if ($this->projector->peopleField($workspaceId) === null) {
                // A workspace that never received the field set: nothing to write.
                continue;
            }

            $workspaces++;
            $stages = $this->projector->stageNamesByContact($workspaceId);
            $stored = $this->storedStages($workspaceId);

            $this->currentWorkspace->within($workspaceId, function () use ($workspaceId, $stages, $stored, $chunk, &$scanned, &$changed): void {
                People::query()
                    ->where('workspace_id', $workspaceId)
                    ->orderBy('id')
                    ->chunkById($chunk, function ($people) use ($stages, $stored, &$scanned, &$changed): void {
                        foreach ($people as $person) {
                            $personId = (string) $person->getKey();
                            $target = LifecycleStage::fromOpportunityStages($stages[$personId] ?? []);

                            $scanned++;

                            if (($stored[$personId] ?? null) === $target) {
                                continue;
                            }

                            if ($this->projector->store($person, $target)) {
                                $changed++;
                            }
                        }
                    });
            });

            $cursor = $this->highWater($workspaceId);

            if ($cursor !== null && ($highWater === null || $cursor > $highWater)) {
                $highWater = $cursor;
            }
        }

        if ($highWater !== null) {
            $this->writeCursor($highWater);
        }

        return [
            'mode' => 'full',
            'workspaces' => $workspaces,
            'scanned' => $scanned,
            'changed' => $changed,
            'cursor' => $this->readCursor(),
        ];
    }

    /**
     * @return array{mode: string, workspaces: int, scanned: int, changed: int, cursor: string|null}
     */
    public function incremental(): array
    {
        $cursor = $this->readCursor();

        if ($cursor === null) {
            // No cursor is not "nothing changed since the beginning of time": it is
            // "this has never run here", and the only honest pass is the full one.
            return $this->full();
        }

        $windowStart = CarbonImmutable::parse($cursor)->subSeconds(self::OVERLAP_SECONDS);
        $workspaces = 0;
        $scanned = 0;
        $changed = 0;
        $highWater = $cursor;

        foreach ($this->workspaceIds() as $workspaceId) {
            if ($this->projector->peopleField($workspaceId) === null) {
                continue;
            }

            $workspaces++;

            [$contactIds, $seen] = $this->touchedContacts($workspaceId, $windowStart);

            if ($seen !== null && $seen > $highWater) {
                $highWater = $seen;
            }

            if ($contactIds === []) {
                continue;
            }

            [$read, $corrected] = $this->projectContacts($workspaceId, $contactIds);
            $scanned += $read;
            $changed += $corrected;
        }

        $this->writeCursor($highWater);

        return [
            'mode' => 'incremental',
            'workspaces' => $workspaces,
            'scanned' => $scanned,
            'changed' => $changed,
            'cursor' => $this->readCursor(),
        ];
    }

    /**
     * Recompute one workspace's touched contacts. Returns `[read, corrected]`.
     *
     * @param  list<string>  $contactIds
     * @return array{int, int}
     */
    private function projectContacts(string $workspaceId, array $contactIds): array
    {
        return $this->currentWorkspace->within($workspaceId, function () use ($workspaceId, $contactIds): array {
            $people = People::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $contactIds)
                ->get();

            $read = 0;
            $corrected = 0;

            foreach ($people as $person) {
                $read++;

                $before = $this->projector->storedStage($person);

                $this->projector->projectPerson($workspaceId, (string) $person->getKey());

                if ($this->projector->storedStage($person->fresh() ?? $person) !== $before) {
                    $corrected++;
                }
            }

            return [$read, $corrected];
        });
    }

    /**
     * The contacts touched since `$windowStart` by either leg, and the newest
     * timestamp either leg saw (the next cursor).
     *
     * @return array{0: list<string>, 1: string|null}
     */
    private function touchedContacts(string $workspaceId, CarbonImmutable $windowStart): array
    {
        // Leg (a): the opportunity ROWS. Creation, a contact move, a soft delete.
        $byOpportunity = DB::table('opportunities')
            ->where('workspace_id', $workspaceId)
            ->where('updated_at', '>=', $windowStart)
            ->get(['id', 'contact_id', 'updated_at']);

        // Leg (b): the STAGE move. `custom_field_values` carries no timestamps at all,
        // and a stage change does not touch the opportunity row, so the activity log is
        // the only durable "something about this deal changed at this instant" record
        // the CRM keeps (every custom-field edit writes one, `CustomFieldChangeLog`).
        // Without this leg a stage move would be invisible to the cursor and the sweep
        // would only ever see it on its nightly full pass.
        $byActivity = DB::table('activity_log')
            ->where('subject_type', $this->opportunityEntityType())
            ->where('created_at', '>=', $windowStart)
            ->get(['subject_id', 'created_at']);

        $opportunityIds = $byOpportunity->pluck('id')
            ->merge($byActivity->pluck('subject_id'))
            ->filter()
            ->unique()
            ->values();

        $seen = $byOpportunity->pluck('updated_at')
            ->merge($byActivity->pluck('created_at'))
            ->filter()
            ->max();

        if ($opportunityIds->isEmpty()) {
            return [[], $seen === null ? null : (string) $seen];
        }

        // The activity leg only knew opportunity ids; the contact is the join. Soft
        // deleted opportunities are included on purpose: a deleted deal still changes
        // its contact's answer.
        $contactIds = DB::table('opportunities')
            ->whereIn('id', $opportunityIds->all())
            ->whereNotNull('contact_id')
            ->pluck('contact_id')
            ->unique()
            ->values()
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return [array_values($contactIds), $seen === null ? null : (string) $seen];
    }

    /**
     * The stored stage of every contact in one workspace, in ONE query: the sweep
     * must not become one read per person.
     *
     * @return array<string, string> person id => option name
     */
    private function storedStages(string $workspaceId): array
    {
        // The stored value is the option ID on one write path and the raw label on
        // another (a direct `saveCustomFields()` call does not normalise a label the way
        // the REST layer does). Resolving both here means the sweep compares against the
        // same answer a human sees, whichever shape the row holds.
        $rows = DB::select(
            'SELECT v.entity_id AS entity_id, o.name AS stage_name
               FROM custom_field_values v
               JOIN custom_fields f ON f.id = v.custom_field_id
               LEFT JOIN custom_field_options o
                      ON o.custom_field_id = v.custom_field_id
                     AND (o.id = v.string_value OR o.name = v.string_value)
              WHERE v.tenant_id = ? AND f.tenant_id = ? AND f.entity_type = ? AND f.code = ?',
            [$workspaceId, $workspaceId, 'people', PeopleField::LIFECYCLE_STAGE->value],
        );

        $map = [];

        foreach ($rows as $row) {
            if ($row->stage_name !== null) {
                $map[(string) $row->entity_id] = (string) $row->stage_name;
            }
        }

        return $map;
    }

    private function highWater(string $workspaceId): ?string
    {
        $stamp = DB::table('opportunities')
            ->where('workspace_id', $workspaceId)
            ->max('updated_at');

        return $stamp === null ? null : (string) $stamp;
    }

    public function readCursor(): ?string
    {
        $value = DB::table('platform_reconcile_cursors')
            ->where('name', self::CURSOR_NAME)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    private function writeCursor(string $cursor): void
    {
        DB::table('platform_reconcile_cursors')->upsert(
            [[
                'name' => self::CURSOR_NAME,
                'value' => $cursor,
                'updated_at' => CarbonImmutable::now(),
            ]],
            ['name'],
            ['value', 'updated_at'],
        );
    }

    /**
     * The LIVE workspaces, and only those.
     *
     * A deprovisioned workspace leaves `custom_fields` rows behind under a tenant id
     * with no `workspaces` row, and the projection cannot run for one: every write
     * needs the workspace context (`CurrentWorkspace::within`), which resolves the
     * model. Measured on DEV050 the hard way: seeding 56 tenant ids into this loop
     * threw `ModelNotFoundException` on the first orphan and aborted the whole pass,
     * leaving every later workspace uncorrected.
     *
     * The orphans are not skipped evidence, they are out of scope: nobody can read a
     * contact in a workspace that no longer exists, and if one is re-provisioned it
     * gets a `workspaces` row and is swept from then on.
     *
     * @return list<string>
     */
    private function workspaceIds(): array
    {
        return array_values(DB::table(Workspace::query()->getModel()->getTable())
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all());
    }

    private function opportunityEntityType(): string
    {
        return Entities::getEntity(Opportunity::class)?->getAlias() ?? 'opportunity';
    }
}
