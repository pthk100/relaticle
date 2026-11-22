<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Listeners\CreateWorkspaceCustomFields;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Carbon\CarbonImmutable;
use Database\Seeders\McnCollaborationFieldsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Facades\Entities;

mutates(OpportunityField::class, PeopleField::class, McnCollaborationFieldsSeeder::class, CreateWorkspaceCustomFields::class);

/**
 * @return list<string>
 */
function mcnCollaborationFieldCodes(): array
{
    return [
        OpportunityField::KOC_STAGE->value,
        OpportunityField::PRODUCT_SKU->value,
        OpportunityField::OPERATOR->value,
        OpportunityField::SAMPLE_TRACKING->value,
        OpportunityField::AFFILIATE_LINK->value,
        OpportunityField::LIVE_POST_URL->value,
        PeopleField::TIKTOK_HANDLE->value,
        PeopleField::POSTAL_ADDRESS->value,
        PeopleField::BLACKLIST_KIND->value,
        PeopleField::BLACKLIST_REASON->value,
    ];
}

/**
 * @return Collection<string, CustomField>
 */
function mcnCollaborationFields(string $tenantId, string $entityType): Collection
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->where('entity_type', $entityType)
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->get()
        ->keyBy(fn (CustomField $field): string => (string) $field->code);
}

/**
 * Gives one workspace the ten codes, the state every tenant is in today.
 */
function giveMcnCollaborationFields(string $tenantId): void
{
    DB::table('custom_fields')
        ->where('tenant_id', $tenantId)
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->delete();

    $now = CarbonImmutable::now();
    $fields = [];
    $options = [];

    $cases = [
        'opportunity' => [
            OpportunityField::KOC_STAGE,
            OpportunityField::PRODUCT_SKU,
            OpportunityField::OPERATOR,
            OpportunityField::SAMPLE_TRACKING,
            OpportunityField::AFFILIATE_LINK,
            OpportunityField::LIVE_POST_URL,
        ],
        'people' => [
            PeopleField::TIKTOK_HANDLE,
            PeopleField::POSTAL_ADDRESS,
            PeopleField::BLACKLIST_KIND,
            PeopleField::BLACKLIST_REASON,
        ],
    ];

    foreach ($cases as $entityType => $entityCases) {
        foreach ($entityCases as $case) {
            $row = CustomFieldRow::build($tenantId, $entityType, $case, $now);
            $fields[] = $row['field'];
            array_push($options, ...$row['options']);
        }
    }

    DB::table('custom_fields')->insert($fields);

    if ($options !== []) {
        DB::table('custom_field_options')->insert($options);
    }
}

/**
 * @param  list<string>  $workspaceIds
 */
function mapMcnOperationsWorkspaces(array $workspaceIds): void
{
    config(['relaticle.operations_workspaces' => $workspaceIds]);
}

function seedMcnCollaborationFields(): void
{
    Artisan::call('db:seed', ['--class' => McnCollaborationFieldsSeeder::class]);
}

test('a run seeds the mapped workspace and prunes the codes from every other one', function (): void {
    $operations = Workspace::factory()->create(['personal_workspace' => false]);
    $brand = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces([(string) $operations->getKey()]);

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    expect(mcnCollaborationFields((string) $operations->getKey(), 'opportunity'))->toHaveCount(6)
        ->and(mcnCollaborationFields((string) $operations->getKey(), 'people'))->toHaveCount(4)
        ->and(mcnCollaborationFields((string) $brand->getKey(), 'opportunity'))->toBeEmpty()
        ->and(mcnCollaborationFields((string) $brand->getKey(), 'people'))->toBeEmpty();
});

test('pruning one workspace removes the ten codes and nothing else', function (): void {
    $operations = Workspace::factory()->create(['personal_workspace' => false]);
    $brand = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces([(string) $operations->getKey()]);

    $before = DB::table('custom_fields')->where('tenant_id', $brand->getKey())->count();

    seedMcnCollaborationFields();

    expect(DB::table('custom_fields')->where('tenant_id', $brand->getKey())->count())->toBe($before - 10)
        ->and(DB::table('custom_fields')
            ->where('tenant_id', $brand->getKey())
            ->whereIn('code', ['stage', 'amount', 'emails', 'brand_code'])
            ->count())->toBe(4);
});

test('a second run inserts and prunes nothing', function (): void {
    $operations = Workspace::factory()->create(['personal_workspace' => false]);
    $brand = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $operations->getKey());
    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces([(string) $operations->getKey()]);

    seedMcnCollaborationFields();

    Artisan::call('db:seed', ['--class' => McnCollaborationFieldsSeeder::class]);

    expect(Artisan::output())->toContain('kept 1 / pruned 0 / inserted 0');
});

test('a code the mapped workspace already defines is left alone, whatever its type', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    $tenantId = (string) $workspace->getKey();

    DB::table('custom_fields')
        ->where('tenant_id', $tenantId)
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->delete();

    CustomField::forceCreate([
        'tenant_id' => $tenantId,
        'entity_type' => 'opportunity',
        'code' => OpportunityField::KOC_STAGE->value,
        'name' => 'KOC Stage (ours)',
        'type' => 'text',
        'sort_order' => 99,
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData,
    ]);

    mapMcnOperationsWorkspaces([$tenantId]);

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    $fields = mcnCollaborationFields($tenantId, 'opportunity');
    $kocStage = $fields[OpportunityField::KOC_STAGE->value];

    expect($fields)->toHaveCount(6)
        ->and($kocStage->type)->toBe('text')
        ->and($kocStage->name)->toBe('KOC Stage (ours)')
        ->and($kocStage->sort_order)->toBe(99)
        ->and($kocStage->system_defined)->toBeFalse();
});

test('the koc stage offers the five ladder values in order, with a colour each', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    $tenantId = (string) $workspace->getKey();

    DB::table('custom_fields')
        ->where('tenant_id', $tenantId)
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->delete();

    mapMcnOperationsWorkspaces([$tenantId]);

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    $field = mcnCollaborationFields($tenantId, 'opportunity')[OpportunityField::KOC_STAGE->value];

    $options = CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->orderBy('sort_order')
        ->get();

    expect($options->pluck('name')->all())
        ->toBe(['no_response', 'sample_pending', 'shipped', 'delivered', 'fulfilled'])
        ->and($options->map(fn (CustomFieldOption $option): ?string => $option->settings?->color)->filter()->all())
        ->toHaveCount(5);
});

test('the people codes land on the people entity the model class resolves to', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    $tenantId = (string) $workspace->getKey();

    DB::table('custom_fields')
        ->where('tenant_id', $tenantId)
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->delete();

    mapMcnOperationsWorkspaces([$tenantId]);

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    $entityType = Entities::getEntity(People::class)?->getAlias();

    $seeded = DB::table('custom_fields')
        ->where('tenant_id', $workspace->getKey())
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->get(['entity_type', 'code']);

    expect($entityType)->toBe('people')
        ->and($seeded)->toHaveCount(10)
        ->and($seeded->unique('entity_type')->pluck('entity_type')->sort()->values()->all())
        ->toBe(['opportunity', 'people'])
        ->and(DB::table('custom_fields')->where('entity_type', 'person')->count())->toBe(0);
});

test('refuses to prune a workspace that holds a value, reporting the count', function (): void {
    $operations = Workspace::factory()->create(['personal_workspace' => false]);
    $brand = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces([(string) $operations->getKey()]);

    $kocStage = mcnCollaborationFields((string) $brand->getKey(), 'opportunity')[OpportunityField::KOC_STAGE->value];

    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'opportunity',
        'entity_id' => (string) Str::ulid(),
        'custom_field_id' => $kocStage->getKey(),
        'tenant_id' => $brand->getKey(),
        'string_value' => 'sample_pending',
    ]);

    $refusal = null;

    try {
        Artisan::call('db:seed', ['--class' => McnCollaborationFieldsSeeder::class]);
    } catch (RuntimeException $exception) {
        $refusal = $exception;
    }

    $output = Artisan::output();

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('refused to prune')
        ->and($refusal->getMessage())->toContain('(values: 1)')
        ->and($output)->toContain('refused (values: 1)')
        ->and(mcnCollaborationFields((string) $brand->getKey(), 'opportunity'))->toHaveCount(6)
        ->and(mcnCollaborationFields((string) $brand->getKey(), 'people'))->toHaveCount(4)
        ->and(DB::table('custom_field_values')->where('custom_field_id', $kocStage->getKey())->count())->toBe(1);
});

test('refuses and names the variable when the operations map is unset', function (): void {
    $brand = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces([]);

    expect(fn () => Artisan::call('db:seed', ['--class' => McnCollaborationFieldsSeeder::class]))
        ->toThrow(RuntimeException::class, 'MCN_OPERATIONS_WORKSPACES is not set')
        ->and(mcnCollaborationFields((string) $brand->getKey(), 'opportunity'))->toHaveCount(6);
});

test('a --workspace option seeds that one workspace and prunes nothing', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    $other = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $other->getKey());
    mapMcnOperationsWorkspaces([]);

    DB::table('custom_fields')
        ->where('tenant_id', $workspace->getKey())
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->delete();

    $this->artisan('platform:seed-collaboration-fields', ['--workspace' => (string) $workspace->getKey()])
        ->assertExitCode(0);

    expect(mcnCollaborationFields((string) $workspace->getKey(), 'opportunity'))->toHaveCount(6)
        ->and(mcnCollaborationFields((string) $workspace->getKey(), 'people'))->toHaveCount(4)
        ->and(mcnCollaborationFields((string) $other->getKey(), 'opportunity'))->toHaveCount(6);
});

test('refuses a map that names a workspace which does not exist', function (): void {
    $brand = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces(['01missingworkspaceqqqqqqqq']);

    expect(fn () => Artisan::call('db:seed', ['--class' => McnCollaborationFieldsSeeder::class]))
        ->toThrow(RuntimeException::class, '01missingworkspaceqqqqqqqq')
        ->and(mcnCollaborationFields((string) $brand->getKey(), 'opportunity'))->toHaveCount(6);
});

test('a new workspace inherits none of the collaboration codes', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    $tenantId = (string) $workspace->getKey();

    $fields = DB::table('custom_fields')->where('tenant_id', $tenantId);

    expect(mcnCollaborationFields($tenantId, 'opportunity'))->toBeEmpty()
        ->and(mcnCollaborationFields($tenantId, 'people'))->toBeEmpty()
        ->and((clone $fields)->where('entity_type', 'company')->where('code', 'brand_code')->count())->toBe(1)
        ->and((clone $fields)->where('entity_type', 'company')->where('code', 'domains')->count())->toBe(1)
        ->and((clone $fields)->where('entity_type', 'opportunity')->where('code', 'stage')->count())->toBe(1)
        ->and((clone $fields)->where('entity_type', 'people')->where('code', 'emails')->count())->toBe(1);
});

test('a brand workspace reads none of the ten codes from the API field list', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $brand = $user->personalWorkspace();
    $operations = Workspace::factory()->create(['personal_workspace' => false]);

    giveMcnCollaborationFields((string) $brand->getKey());
    mapMcnOperationsWorkspaces([(string) $operations->getKey()]);

    seedMcnCollaborationFields();

    Sanctum::actingAs($user);

    foreach (['opportunity', 'people'] as $entityType) {
        $codes = collect($this->getJson("/api/v1/custom-fields?entity_type={$entityType}&per_page=100")
            ->assertOk()
            ->json('data'))
            ->pluck('attributes.code')
            ->all();

        expect(array_values(array_intersect($codes, mcnCollaborationFieldCodes())))
            ->toBe([])
            ->and($codes)->toContain($entityType === 'opportunity' ? 'stage' : 'emails');
    }
});

test('the operations map is parsed from MCN_OPERATIONS_WORKSPACES', function (): void {
    $repository = Env::getRepository();
    $repository->set('MCN_OPERATIONS_WORKSPACES', 'TH:01thqqqqqqqqqqqqqqqqqqqq, MY: 01myqqqqqqqqqqqqqqqqqqqq');

    try {
        $config = require config_path('relaticle.php');
    } finally {
        $repository->clear('MCN_OPERATIONS_WORKSPACES');
    }

    expect($config['operations_workspaces'])
        ->toBe(['01thqqqqqqqqqqqqqqqqqqqq', '01myqqqqqqqqqqqqqqqqqqqq']);
});

test('an unset operations map parses to no workspace', function (): void {
    $repository = Env::getRepository();
    $repository->clear('MCN_OPERATIONS_WORKSPACES');

    $config = require config_path('relaticle.php');

    expect($config['operations_workspaces'])->toBe([]);
});
