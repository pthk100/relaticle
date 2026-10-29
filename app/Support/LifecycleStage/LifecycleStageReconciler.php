<?php

declare(strict_types=1);

namespace App\Support\LifecycleStage;

use App\Enums\CustomFields\PeopleField;
use App\Models\People;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * THE RECONCILIATION PASS for `lifecycle_stage` (t_7273de85, t_885ca3bf).
 *
 * ── Why an observer is not enough ───────────────────────────────────────────
 * The observers (`OpportunityObserver`, `CustomFieldValueObserver`) recompute the
 * contact inside the write, so an ordinary stage change is immediate. They cannot
 * cover everything: a value row written straight into Postgres, a restored backup,
 * a row queued before this field existed, a workspace that received the field
 * after its deals were already closed, or a projection that failed while the CRM
 * was mid-rollout. The sweep is what makes the field TRUE rather than fresh.
 *
 * ── Why the pass does not trust a cursor (t_885ca3bf) ───────────────────────
 * It used to. The five-minute tick selected contacts by two timestamp legs since
 * `cursor - OVERLAP_SECONDS`: `opportunities.updated_at`, and `activity_log` rows
 * about an opportunity. Neither leg can see the case this class exists for.
 * `custom_field_values` carries NO timestamps at all (`\d custom_field_values`:
 * id/tenant/entity/custom_field/value: no created_at, no updated_at) and a psql
 * write fires no audit trigger, so a value written behind the application was
 * invisible to the tick and waited for the nightly `--full`.
 *
 * Measured on DEV050 2026-10-28 (docs/ops/t_ad051f97-scheduler-evidence.md §4):
 * two contacts corrupted at the same instant, one whose deals had moved inside
 * the window and one whose had not. The first came back on the 19:30:05 tick; the
 * second was still wrong after the 19:30:05 AND 19:35:04 ticks, and only the full
 * pass fixed it. That is the shape of the bug: the tick's coverage was a function
 * of WHEN the contact was last touched, and a contact nobody touches is exactly
 * the one whose stage drifts unnoticed.
 *
 * So the tick now answers the question directly instead of inferring it from
 * change times: COMPARE the stored stage of every contact against the stage its
 * live deals justify, and write only the ones that disagree. That is O(contacts)
 * per tick rather than O(changes), which is the trade this card takes (option 1)
 * and the reason for `DEFAULT_CONTACT_BUDGET` below: today's fleet is 61 contacts
 * in 47 workspaces (one second, measured), and the budget is where that stops
 * being a free assumption.
 *
 * ── Why not the alternatives ────────────────────────────────────────────────
 * A trigger on `custom_field_values` writing an `activity_log` row WOULD see a
 * psql write, and it costs O(changes). It also makes every derived write produce
 * an activity row unless the trigger excludes this field id, which contradicts
 * the projector's stated contract ("a derived change never appears in the
 * contact's activity timeline as if a human had made it"), puts a per-row join
 * on the CRM's hottest write path, and puts `lifecycle_stage` corrections into
 * `activity_log`, which is ADR-4's CDC source. A stored per-workspace digest
 * (option 3) needs a state table that can itself be stale, and one wrong digest
 * silently re-creates this same bug. Comparing the answer costs a scan and needs
 * no new state, so it cannot be wrong about whether there is drift.
 *
 * ── Two modes, one comparison ───────────────────────────────────────────────
 * `--incremental` is the five-minute tick: every live workspace, under a contact
 * budget (`--budget`, default 5000). `--full` is the nightly pass and the
 * operator's on-demand one: identical work with the budget switched off. Both are
 * complete at this fleet size; the budget only names the point where the tick
 * starts deferring workspaces to the next tick instead of overrunning its slot.
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
     * How many contacts ONE tick may compare before it defers the remaining
     * workspaces to the next tick.
     *
     * Not a performance guard for today: DEV050's whole fleet is 61 contacts and
     * 47 workspaces, and a complete pass takes about a second (measured
     * 2026-10-28). It is the number that stops "O(contacts) every five minutes"
     * from being an unstated assumption. At 5,000 the tick still finishes in a
     * few seconds, and a fleet that outgrows it starts rotating instead of
     * overrunning. The nightly `--full` has no budget, so nothing can stay
     * deferred for more than a day even if the tick is starved every time.
     */
    public const int DEFAULT_CONTACT_BUDGET = 5000;

    /** Contacts read per query when the pass has something to write. */
    private const int WRITE_CHUNK = 500;

    public function __construct(
        private LifecycleStageProjector $projector,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * The nightly pass: every contact of every live workspace.
     *
     * @return array{mode: string, workspaces: int, compared: int, changed: int, deferred: int, cursor: string|null}
     */
    public function full(int $chunk = self::WRITE_CHUNK): array
    {
        return $this->sweep('full', $chunk, null);
    }

    /**
     * The five-minute tick: the same comparison, with the per-tick contact budget.
     *
     * @return array{mode: string, workspaces: int, compared: int, changed: int, deferred: int, cursor: string|null}
     */
    public function incremental(int $budget = self::DEFAULT_CONTACT_BUDGET, int $chunk = self::WRITE_CHUNK): array
    {
        return $this->sweep('incremental', $chunk, $budget);
    }

    /**
     * One pass. The only difference between the two public modes is `$budget`.
     *
     * @return array{mode: string, workspaces: int, compared: int, changed: int, deferred: int, cursor: string|null}
     */
    private function sweep(string $mode, int $chunk, ?int $budget): array
    {
        $workspaceIds = $this->workspaceIds();
        $workspaces = 0;
        $compared = 0;
        $changed = 0;
        $deferred = 0;
        $highWater = null;

        foreach ($workspaceIds as $index => $workspaceId) {
            $field = $this->projector->peopleField($workspaceId);

            if ($field === null) {
                // A workspace that never received the field set: nothing to write.
                continue;
            }

            $targets = $this->driftSet($workspaceId, $field);

            // A workspace is the unit of work, never half of one: the budget is
            // checked before it is started, so a deferral costs a tick and never a
            // torn comparison. The first workspace is always taken, so a budget
            // smaller than one workspace cannot stop the pass making progress.
            if ($budget !== null && $workspaces > 0 && $compared + $targets['compared'] > $budget) {
                $deferred = count($workspaceIds) - $index;

                break;
            }

            $workspaces++;
            $compared += $targets['compared'];

            // Only the correction count is reported: the write path's own `read` is
            // the same number unless a contact vanished between the comparison and the
            // write, and an operator reads `compared` for coverage.
            [, $corrected] = $this->write($workspaceId, $targets['people'], $chunk);
            $changed += $corrected;

            $cursor = $this->highWater($workspaceId);

            if ($cursor !== null && ($highWater === null || $cursor > $highWater)) {
                $highWater = $cursor;
            }
        }

        if ($highWater !== null) {
            $this->writeCursor($highWater);
        }

        return [
            'mode' => $mode,
            'workspaces' => $workspaces,
            'compared' => $compared,
            'changed' => $changed,
            'deferred' => $deferred,
            'cursor' => $this->readCursor(),
        ];
    }

    /**
     * The contacts of one workspace whose stored stage the deals do not justify,
     * with the stage each one should hold.
     *
     * ── Why the candidate set is three queries and not one ──────────────────────
     * A contact can be wrong in any of three places, and each is a different row:
     *
     *   · it has a `lifecycle_stage` value row holding the wrong option (the psql
     *     write, the restore, the projection that failed): `storedStages()`;
     *   · it has live deals whose stages justify a different answer: every such
     *     contact appears in `stageNamesByContact()`;
     *   · it has no usable value row at all and no deal, so it appears in NEITHER
     *     map and would be invisible. `contactsWithoutAStageValue()` catches it. A contact
     *     in the address book with no deal is `lead`, and "the row is missing" is
     *     not the same fact as "the row says lead".
     *
     * The union of the three is every contact in the workspace, so the comparison
     * is complete: nothing is inferred from WHEN anything was written.
     *
     * @param  array{id: string, type: string, entity_type: string}  $field
     * @return array{compared: int, people: array<string, string>} compared, and person id => the stage it should hold
     */
    private function driftSet(string $workspaceId, array $field): array
    {
        $stages = $this->projector->stageNamesByContact($workspaceId);
        $stored = $this->storedStages($workspaceId);
        $withoutValue = $this->contactsWithoutAStageValue($workspaceId, $field);

        $candidates = $stages + array_fill_keys(array_keys($stored), []);

        foreach ($withoutValue as $personId) {
            $candidates[$personId] ??= [];
        }

        $people = [];

        foreach ($candidates as $personId => $stageNames) {
            $personId = (string) $personId;
            $target = LifecycleStage::fromOpportunityStages($stageNames);

            if (($stored[$personId] ?? null) !== $target) {
                $people[$personId] = $target;
            }
        }

        return ['compared' => count($candidates), 'people' => $people];
    }

    /**
     * Write the contacts the comparison found, one chunk at a time.
     *
     * Only the drift is loaded: the previous shape walked every contact of the
     * workspace through the model layer to compare it, which turned a pass that
     * should cost one row per wrong contact into a read of the whole address book.
     *
     * @param  array<string, string>  $targets  person id => the stage it should hold
     * @return array{int, int} contacts read, contacts corrected
     */
    private function write(string $workspaceId, array $targets, int $chunk): array
    {
        if ($targets === []) {
            return [0, 0];
        }

        $chunk = max(1, $chunk);

        return $this->currentWorkspace->within($workspaceId, function () use ($workspaceId, $targets, $chunk): array {
            $read = 0;
            $corrected = 0;

            foreach (array_chunk($targets, $chunk, true) as $batch) {
                $people = People::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', array_keys($batch))
                    ->get();

                foreach ($people as $person) {
                    $read++;

                    $target = $batch[(string) $person->getKey()] ?? null;

                    if ($target === null) {
                        continue;
                    }

                    // The projector is the ONLY writer, and it writes only when the
                    // stored value differs from the one it is handed.
                    if ($this->projector->store($person, $target)) {
                        $corrected++;
                    }
                }
            }

            return [$read, $corrected];
        });
    }

    /**
     * The stored stage of every contact in one workspace that HAS a usable value,
     * in ONE query: the pass must not become one read per person.
     *
     * The stored value is the option ID on one write path and the raw label on
     * another (a direct `saveCustomFields()` call does not normalise a label the
     * way the REST layer does), and a restore can leave it pointing at an option
     * that no longer exists. Resolving all three shapes here means the comparison
     * reads the same answer a human sees, and a value nothing can resolve is
     * reported as itself, so it compares unequal to the derived answer and gets
     * corrected instead of looking like "no value at all".
     *
     * @return array<string, string> person id => option name (or the raw stored value)
     */
    private function storedStages(string $workspaceId): array
    {
        $rows = DB::select(
            'SELECT v.entity_id AS entity_id,
                    COALESCE(o.name, v.string_value, v.integer_value::text) AS stage_name
               FROM custom_field_values v
               JOIN custom_fields f ON f.id = v.custom_field_id
               LEFT JOIN custom_field_options o
                      ON o.custom_field_id = v.custom_field_id
                     AND (o.id = v.string_value OR o.name = v.string_value)
              WHERE v.tenant_id = ? AND f.tenant_id = ? AND f.entity_type = ? AND f.code = ?
                AND COALESCE(v.string_value, v.integer_value::text) IS NOT NULL
                AND COALESCE(v.string_value, v.integer_value::text) <> \'\'',
            [$workspaceId, $workspaceId, 'people', PeopleField::LIFECYCLE_STAGE->value],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->entity_id] = (string) $row->stage_name;
        }

        return $map;
    }

    /**
     * The contacts of one workspace with no usable `lifecycle_stage` value row.
     *
     * These are the contacts the two comparison maps cannot see: no row means no
     * entry in `storedStages()`, and no deal means no entry in
     * `stageNamesByContact()`. A contact that predates the field, or whose value
     * row was deleted outright, is exactly that shape, and it is the case the
     * nightly sweep's own doc comment names, so the tick must see it too.
     *
     * @param  array{id: string, type: string, entity_type: string}  $field
     * @return list<string>
     */
    private function contactsWithoutAStageValue(string $workspaceId, array $field): array
    {
        /** @var list<object{id: string}> $rows */
        $rows = DB::select(
            'SELECT p.id AS id
               FROM people p
               LEFT JOIN custom_field_values v
                      ON v.entity_id = p.id
                     AND v.entity_type = ?
                     AND v.tenant_id = ?
                     AND v.custom_field_id = ?
              WHERE p.workspace_id = ? AND p.deleted_at IS NULL
                AND (v.id IS NULL
                     OR COALESCE(v.string_value, v.integer_value::text) IS NULL
                     OR COALESCE(v.string_value, v.integer_value::text) = \'\')',
            ['people', $workspaceId, $field['id'], $workspaceId],
        );

        return array_map(static fn (object $row): string => (string) $row->id, $rows);
    }

    /**
     * The newest `opportunities.updated_at` in the workspace.
     *
     * Kept for what a cursor is still good for: an operator reading
     * `platform_reconcile_cursors` sees how far the opportunity stream had been
     * read at the last pass. It gates nothing. See the class comment for why the
     * pass stopped trusting it (t_885ca3bf).
     */
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
}
