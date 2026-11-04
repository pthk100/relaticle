<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Database\Seeders\McnBrandFieldsSeeder;

mutates(CompanyField::class, McnBrandFieldsSeeder::class);

test('a workspace carries the brand code and category fields on the company entity', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);

    $fields = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'company')
        ->whereIn('code', [CompanyField::BRAND_CODE->value, CompanyField::CATEGORY->value])
        ->get()
        ->keyBy('code');

    expect($fields)->toHaveCount(2)
        ->and($fields[CompanyField::BRAND_CODE->value]->name)->toBe('Brand code')
        ->and($fields[CompanyField::CATEGORY->value]->name)->toBe('Category')
        ->and($fields[CompanyField::BRAND_CODE->value]->active)->toBeTrue()
        ->and($fields[CompanyField::CATEGORY->value]->active)->toBeTrue();
});

test('a company stores a brand code and a category', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->personalWorkspace();

    $company = resolve(CurrentWorkspace::class)->within($workspace, fn (): Company => Company::factory()->for($workspace)->create([
        'name' => 'Acme Beauty',
        'custom_fields' => [
            CompanyField::BRAND_CODE->value => 'ACME-BEAUTY-01',
            CompanyField::CATEGORY->value => 'Beauty',
        ],
    ]));

    $stored = $company->fresh('customFieldValues.customField');

    $field = fn (string $code): CustomField => CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'company')
        ->where('code', $code)
        ->firstOrFail();

    expect($stored->getCustomFieldValue($field(CompanyField::BRAND_CODE->value)))->toBe('ACME-BEAUTY-01')
        ->and($stored->getCustomFieldValue($field(CompanyField::CATEGORY->value)))->toBe('Beauty');
});

test('seeding the brand fields twice leaves exactly the two of them', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => false]);

    $companyFields = fn () => CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'company');

    $brandFields = fn () => $companyFields()
        ->whereIn('code', [CompanyField::BRAND_CODE->value, CompanyField::CATEGORY->value]);

    $declared = $companyFields()->count();

    $brandFields()->delete();

    expect($brandFields()->count())->toBe(0);

    $this->artisan('db:seed', ['--class' => McnBrandFieldsSeeder::class])->assertExitCode(0);
    $this->artisan('db:seed', ['--class' => McnBrandFieldsSeeder::class])->assertExitCode(0);

    $seeded = $brandFields()->get()->keyBy('code');

    expect($seeded)->toHaveCount(2)
        ->and($seeded[CompanyField::BRAND_CODE->value]->name)->toBe('Brand code')
        ->and($seeded[CompanyField::BRAND_CODE->value]->type)->toBe('text')
        ->and($seeded[CompanyField::CATEGORY->value]->name)->toBe('Category')
        ->and($seeded[CompanyField::CATEGORY->value]->settings->description)->toBe('How the MCN team files the brand')
        ->and($companyFields()->count())->toBe($declared);
});
