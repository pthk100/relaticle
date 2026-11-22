<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;
use RuntimeException;

/**
 * Puts the KOC collaboration fields in the operations workspace, and nowhere else.
 *
 * The map is `MCN_OPERATIONS_WORKSPACES`, the same variable the platform's
 * orchestrator resolves its market workspaces from. Workspaces outside the map
 * lose the codes: they are a brand tenant's own sales surface, and a KOC ladder
 * in an opportunity picker is a feature it cannot use.
 *
 * Idempotent by construction: a code already present in the mapped workspace is
 * left in place, so a second run inserts and prunes nothing.
 */
final class McnCollaborationFieldsSeeder extends Seeder
{
    private const string OPERATIONS_MAP = 'MCN_OPERATIONS_WORKSPACES';

    /**
     * @param  string|null  $workspace  One workspace id to seed, instead of the map.
     */
    public function run(?string $workspace = null): void
    {
        $now = CarbonImmutable::now();
        $targets = $workspace === null ? $this->mappedWorkspaceIds() : [$workspace];

        $this->assertWorkspacesExist($targets);

        $kept = 0;
        $pruned = 0;
        $inserted = 0;

        /** @var array<string, int> $refused values held, per workspace */
        $refused = [];

        foreach (Workspace::query()->orderBy('slug')->get(['id', 'slug']) as $candidate) {
            $tenantId = (string) $candidate->getKey();
            $label = "{$candidate->slug} ({$tenantId})";

            if (in_array($tenantId, $targets, true)) {
                $inserted += $this->seedWorkspace($tenantId, $now);
                $kept++;
                $this->command?->line("{$label}: kept");

                continue;
            }

            if ($workspace !== null) {
                continue;
            }

            $values = $this->valueCount($tenantId);

            if ($values > 0) {
                $refused[$tenantId] = $values;
                $this->command?->warn("{$label}: refused (values: {$values})");

                continue;
            }

            $deleted = $this->pruneWorkspace($tenantId);

            if ($deleted > 0) {
                $pruned++;
            }

            $this->command?->line("{$label}: pruned {$deleted}");
        }

        foreach ($this->entityTypes() as $entityType) {
            BackendVisibilityService::clearCache($entityType);
        }

        $this->command?->line("MCN collaboration fields: kept {$kept} / pruned {$pruned} / inserted {$inserted}");

        if ($refused !== []) {
            throw new RuntimeException($this->refusalReport($refused));
        }
    }

    private function seedWorkspace(string $tenantId, CarbonImmutable $now): int
    {
        return $this->seedEntity($tenantId, Opportunity::class, $this->opportunityCodes(), $now)
            + $this->seedEntity($tenantId, People::class, $this->peopleCodes(), $now);
    }

    /**
     * @param  class-string  $model
     * @param  list<OpportunityField|PeopleField>  $cases
     * @return int Rows created; 0 when the workspace already carries the codes.
     */
    private function seedEntity(string $tenantId, string $model, array $cases, CarbonImmutable $now): int
    {
        $entityType = $this->entityType($model);

        $existing = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->whereIn('code', array_map(static fn (OpportunityField|PeopleField $case): string => $case->value, $cases))
            ->pluck('code')
            ->all();

        $fields = [];
        $options = [];

        foreach ($cases as $case) {
            if (in_array($case->value, $existing, true)) {
                continue;
            }

            $row = CustomFieldRow::build($tenantId, $entityType, $case, $now);
            $fields[] = $row['field'];
            array_push($options, ...$row['options']);
        }

        if ($fields === []) {
            return 0;
        }

        // insert() fires no model events: no one edited these rows, so nothing
        // reaches the audit log (same rationale as McnBrandFieldsSeeder).
        DB::transaction(static function () use ($fields, $options): void {
            CustomField::query()->insert($fields);

            if ($options !== []) {
                CustomFieldOption::query()->insert($options);
            }
        });

        return count($fields);
    }

    /**
     * @return list<string>
     */
    private function mappedWorkspaceIds(): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            (array) config('relaticle.operations_workspaces', []),
        )));

        throw_if($ids === [], RuntimeException::class, self::OPERATIONS_MAP.' is not set; refusing to guess which workspace owns the KOC collaboration fields');

        return $ids;
    }

    /**
     * @param  list<string>  $targets
     */
    private function assertWorkspacesExist(array $targets): void
    {
        $existing = Workspace::query()
            ->whereIn('id', $targets)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $missing = array_values(array_diff($targets, $existing));

        if ($missing !== []) {
            throw new RuntimeException(
                self::OPERATIONS_MAP.' names workspace id(s) that do not exist: '.implode(', ', $missing),
            );
        }
    }

    private function valueCount(string $tenantId): int
    {
        return DB::table('custom_field_values')
            ->join('custom_fields', 'custom_fields.id', '=', 'custom_field_values.custom_field_id')
            ->where('custom_fields.tenant_id', $tenantId)
            ->whereIn('custom_fields.code', $this->codes())
            ->count();
    }

    private function pruneWorkspace(string $tenantId): int
    {
        // A mass delete fires no model events; options and values go with the
        // rows through the foreign keys' ON DELETE CASCADE.
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', $this->codes())
            ->delete();
    }

    /**
     * @param  array<string, int>  $refused
     */
    private function refusalReport(array $refused): string
    {
        $lines = [];

        foreach ($refused as $tenantId => $values) {
            $lines[] = "{$tenantId} (values: {$values})";
        }

        return 'MCN collaboration fields: refused to prune '.count($refused)
            .' workspace(s) holding values for the collaboration codes: '.implode(', ', $lines);
    }

    /**
     * @param  class-string  $model
     */
    private function entityType(string $model): string
    {
        return Entities::getEntity($model)?->getAlias() ?? $model;
    }

    /**
     * @return list<string>
     */
    private function entityTypes(): array
    {
        return [$this->entityType(Opportunity::class), $this->entityType(People::class)];
    }

    /**
     * @return list<string>
     */
    private function codes(): array
    {
        return array_map(
            static fn (OpportunityField|PeopleField $case): string => $case->value,
            [...$this->opportunityCodes(), ...$this->peopleCodes()],
        );
    }

    /**
     * @return list<OpportunityField>
     */
    private function opportunityCodes(): array
    {
        return array_values(array_filter(
            OpportunityField::cases(),
            static fn (OpportunityField $case): bool => $case->belongsToOperationsWorkspace(),
        ));
    }

    /**
     * @return list<PeopleField>
     */
    private function peopleCodes(): array
    {
        return array_values(array_filter(
            PeopleField::cases(),
            static fn (PeopleField $case): bool => $case->belongsToOperationsWorkspace(),
        ));
    }
}
