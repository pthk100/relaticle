<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\People;
use App\Models\Workspace;
use Database\Seeders\McnCollaborationFieldsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Facades\Entities;

mutates(OpportunityField::class, PeopleField::class, McnCollaborationFieldsSeeder::class);

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

function forgetMcnCollaborationFields(string $tenantId): void
{
    DB::table('custom_fields')
        ->where('tenant_id', $tenantId)
        ->whereIn('code', mcnCollaborationFieldCodes())
        ->delete();
}

test('every workspace gets the six opportunity and four people collaboration codes', function (): void {
    $workspaces = Workspace::factory()->count(2)->create(['personal_workspace' => false]);

    foreach ($workspaces as $workspace) {
        forgetMcnCollaborationFields((string) $workspace->getKey());
    }

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    foreach ($workspaces as $workspace) {
        $opportunity = mcnCollaborationFields((string) $workspace->getKey(), 'opportunity');
        $people = mcnCollaborationFields((string) $workspace->getKey(), 'people');

        expect($opportunity)->toHaveCount(6)
            ->and($people)->toHaveCount(4)
            ->and($opportunity->keys()->sort()->values()->all())->toBe([
                'affiliate_link',
                'koc_stage',
                'live_post_url',
                'operator',
                'product_sku',
                'sample_tracking',
            ])
            ->and($people->keys()->sort()->values()->all())->toBe([
                'blacklist_kind',
                'blacklist_reason',
                'postal_address',
                'tiktok_handle',
            ]);
    }
});

test('each collaboration code carries the type the platform writes into it', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    forgetMcnCollaborationFields((string) $workspace->getKey());

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    $types = mcnCollaborationFields((string) $workspace->getKey(), 'opportunity')
        ->map(fn (CustomField $field): string => (string) $field->type)
        ->merge(mcnCollaborationFields((string) $workspace->getKey(), 'people')
            ->map(fn (CustomField $field): string => (string) $field->type))
        ->sortKeys()
        ->all();

    expect($types)->toBe([
        'affiliate_link' => 'link',
        'blacklist_kind' => 'text',
        'blacklist_reason' => 'text',
        'koc_stage' => 'radio',
        'live_post_url' => 'link',
        'operator' => 'text',
        'postal_address' => 'textarea',
        'product_sku' => 'text',
        'sample_tracking' => 'text',
        'tiktok_handle' => 'text',
    ]);
});

test('a second run inserts nothing into any workspace', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    forgetMcnCollaborationFields((string) $workspace->getKey());

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    $before = CustomField::query()
        ->withoutGlobalScopes()
        ->get(['tenant_id', 'entity_type', 'code'])
        ->groupBy(fn (CustomField $field): string => $field->tenant_id.'|'.$field->entity_type)
        ->map(fn (Collection $fields): int => $fields->count())
        ->sortKeys()
        ->all();

    Artisan::call('db:seed', ['--class' => McnCollaborationFieldsSeeder::class]);
    $output = Artisan::output();

    $after = CustomField::query()
        ->withoutGlobalScopes()
        ->get(['tenant_id', 'entity_type', 'code'])
        ->groupBy(fn (CustomField $field): string => $field->tenant_id.'|'.$field->entity_type)
        ->map(fn (Collection $fields): int => $fields->count())
        ->sortKeys()
        ->all();

    expect($after)->toBe($before)
        ->and($output)->not->toContain('inserted');
});

test('a code the workspace already defines is left alone, whatever its type', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);
    $tenantId = (string) $workspace->getKey();

    forgetMcnCollaborationFields($tenantId);

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
    forgetMcnCollaborationFields((string) $workspace->getKey());

    $this->artisan('db:seed', ['--class' => McnCollaborationFieldsSeeder::class])->assertExitCode(0);

    $field = mcnCollaborationFields((string) $workspace->getKey(), 'opportunity')[OpportunityField::KOC_STAGE->value];

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
    forgetMcnCollaborationFields((string) $workspace->getKey());

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
