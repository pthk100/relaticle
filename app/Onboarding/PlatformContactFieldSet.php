<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\People;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;

/**
 * Applies the platform contact field set to an EXISTING workspace.
 *
 * New workspaces get the set from CreateWorkspaceCustomFields, which reads the
 * same PeopleField enum; this class exists for workspaces that predate a code.
 * It is idempotent by construction: a code that is already present is left in
 * place, so running it twice inserts zero rows. A few previously seeded codes
 * carry settings the platform has since decided differently (phone_number is
 * now a unique identity field, and the set has a fixed display order); those
 * are converged in place, and only when the stored value actually differs, so
 * a second run writes nothing at all.
 */
final readonly class PlatformContactFieldSet
{
    public static function entityType(): string
    {
        return Entities::getEntity(People::class)?->getAlias() ?? 'people';
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(
            static fn (PeopleField $field): string => $field->value,
            PeopleField::cases(),
        );
    }

    /**
     * Seed any missing contact field into one workspace.
     *
     * @return int Rows created; 0 when the workspace already has the set.
     */
    public static function applyTo(string $tenantId): int
    {
        $now = CarbonImmutable::now();
        $entityType = self::entityType();

        $existing = DB::table('custom_fields')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->get(['code', 'sort_order', 'settings'])
            ->keyBy('code');

        $fields = [];
        $options = [];

        foreach (PeopleField::cases() as $case) {
            if ($existing->has($case->value)) {
                continue;
            }

            $row = CustomFieldRow::build($tenantId, $entityType, $case, $now);
            $fields[] = $row['field'];
            array_push($options, ...$row['options']);
        }

        DB::transaction(function () use ($fields, $options): void {
            // insert() fires no model events: these rows are not an edit anyone
            // made, so they write nothing to the audit log (same rationale as
            // the workspace-created listener).
            foreach (array_chunk($fields, 100) as $chunk) {
                CustomField::query()->insert($chunk);
            }

            foreach (array_chunk($options, 500) as $chunk) {
                CustomFieldOption::query()->insert($chunk);
            }
        });

        self::converge($tenantId, $entityType);

        BackendVisibilityService::clearCache($entityType);

        return count($fields);
    }

    /**
     * Seed every workspace that exists now, plus any tenant id that still holds
     * custom fields (a deprovisioned workspace can leave rows behind, and a set
     * that is 25 deep everywhere except those would read as a partial backfill).
     *
     * @return array{tenants: int, inserted: int}
     */
    public static function applyToEveryTenant(): array
    {
        $tenants = DB::table(Workspace::query()->getModel()->getTable())
            ->pluck('id')
            ->merge(DB::table('custom_fields')->distinct()->pluck('tenant_id'))
            ->unique()
            ->values();

        $inserted = 0;

        foreach ($tenants as $tenantId) {
            $inserted += self::applyTo((string) $tenantId);
        }

        return ['tenants' => $tenants->count(), 'inserted' => $inserted];
    }

    /**
     * Bring the settings of already-seeded codes in line with the declaration,
     * writing only the rows that differ.
     */
    private static function converge(string $tenantId, string $entityType): void
    {
        $rows = DB::table('custom_fields')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->whereIn('code', self::codes())
            ->get(['code', 'sort_order', 'settings']);

        foreach ($rows as $row) {
            $case = PeopleField::from((string) $row->code);
            $changes = [];
            $sortOrder = $case->getSortOrder();

            if ((int) $row->sort_order !== $sortOrder) {
                $changes['sort_order'] = $sortOrder;
            }

            $settings = is_string($row->settings)
                ? (array) json_decode($row->settings, true)
                : (array) $row->settings;

            $unique = $case->isUniquePerEntityType();

            if (($settings['unique_per_entity_type'] ?? false) !== $unique) {
                $settings['unique_per_entity_type'] = $unique;
                $changes['settings'] = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($changes === []) {
                continue;
            }

            $changes['updated_at'] = CarbonImmutable::now();

            DB::table('custom_fields')
                ->where('tenant_id', $tenantId)
                ->where('entity_type', $entityType)
                ->where('code', $row->code)
                ->update($changes);
        }
    }
}
