<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;

/**
 * Seeds the MCN brand fields onto the company entity in every workspace.
 *
 * New workspaces get them from CreateWorkspaceCustomFields, which reads the same
 * CompanyField enum; this runs for workspaces that predate the codes. It is
 * idempotent by construction: a code already present is left in place, so a
 * second run inserts zero rows and never rewrites a workspace's own settings.
 */
final class McnBrandFieldsSeeder extends Seeder
{
    public function run(): void
    {
        $entityType = Entities::getEntity(Company::class)?->getAlias() ?? 'company';
        $now = CarbonImmutable::now();

        foreach (Workspace::query()->pluck('id') as $tenantId) {
            $this->seedMissing((string) $tenantId, $entityType, $now);
        }

        BackendVisibilityService::clearCache($entityType);
    }

    private function seedMissing(string $tenantId, string $entityType, CarbonImmutable $now): void
    {
        $existing = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->whereIn('code', $this->codes())
            ->pluck('code')
            ->all();

        $rows = [];

        foreach ($this->codes() as $case) {
            if (in_array($case->value, $existing, true)) {
                continue;
            }

            $rows[] = CustomFieldRow::build($tenantId, $entityType, $case, $now)['field'];
        }

        if ($rows === []) {
            return;
        }

        // insert() fires no model events: no one edited these rows, so nothing
        // reaches the audit log (same rationale as PlatformContactFieldSet).
        CustomField::query()->insert($rows);
    }

    /**
     * @return list<CompanyField>
     */
    private function codes(): array
    {
        return [CompanyField::BRAND_CODE, CompanyField::CATEGORY];
    }
}
