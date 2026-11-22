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

/**
 * Seeds the KOC collaboration fields onto the opportunity and people entities
 * in every workspace.
 *
 * New workspaces get them from CreateWorkspaceCustomFields, which reads the same
 * enums; this runs for workspaces that predate the codes, so the platform's
 * projection finds the same schema in every tenant. It is idempotent by
 * construction: a code already present is left in place, so a second run inserts
 * zero rows and never rewrites a workspace's own settings.
 */
final class McnCollaborationFieldsSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now();
        $inserted = 0;

        foreach (Workspace::query()->pluck('id') as $tenantId) {
            $inserted += $this->seedEntity((string) $tenantId, Opportunity::class, $this->opportunityCodes(), $now);
            $inserted += $this->seedEntity((string) $tenantId, People::class, $this->peopleCodes(), $now);
        }

        foreach ([Opportunity::class, People::class] as $model) {
            BackendVisibilityService::clearCache($this->entityType($model));
        }

        if ($inserted > 0) {
            $this->command?->line("MCN collaboration fields: {$inserted} inserted");
        }
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
     * @param  class-string  $model
     */
    private function entityType(string $model): string
    {
        return Entities::getEntity($model)?->getAlias() ?? $model;
    }

    /**
     * @return list<OpportunityField>
     */
    private function opportunityCodes(): array
    {
        return [
            OpportunityField::KOC_STAGE,
            OpportunityField::PRODUCT_SKU,
            OpportunityField::OPERATOR,
            OpportunityField::SAMPLE_TRACKING,
            OpportunityField::AFFILIATE_LINK,
            OpportunityField::LIVE_POST_URL,
        ];
    }

    /**
     * @return list<PeopleField>
     */
    private function peopleCodes(): array
    {
        return [
            PeopleField::TIKTOK_HANDLE,
            PeopleField::POSTAL_ADDRESS,
            PeopleField::BLACKLIST_KIND,
            PeopleField::BLACKLIST_REASON,
        ];
    }
}
