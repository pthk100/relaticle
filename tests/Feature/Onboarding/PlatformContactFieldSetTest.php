<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Listeners\CreateWorkspaceCustomFields;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Workspace;
use App\Onboarding\PlatformContactFieldSet;
use Illuminate\Support\Facades\DB;

mutates(CreateWorkspaceCustomFields::class, PlatformContactFieldSet::class);

/**
 * @return array<string, CustomField>
 */
function contactFields(string $workspaceId): array
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspaceId)
        ->where('entity_type', 'people')
        ->get()
        ->keyBy(fn (CustomField $field): string => (string) $field->code)
        ->all();
}

test('a new workspace starts with every contact field, once each', function () {
    $workspace = Workspace::factory()->create();

    $codes = array_keys(contactFields($workspace->getKey()));

    expect(collect($codes)->sort()->values()->all())
        ->toBe(collect(PlatformContactFieldSet::codes())->sort()->values()->all())
        ->and($codes)->toHaveCount(count(PeopleField::cases()));
});

test('re-applying the set to a workspace that has it creates nothing', function () {
    $workspace = Workspace::factory()->create();
    $before = count(contactFields($workspace->getKey()));

    expect(PlatformContactFieldSet::applyTo($workspace->getKey()))->toBe(0)
        ->and(contactFields($workspace->getKey()))->toHaveCount($before);
});

test('re-applying the set to a workspace that predates it inserts only what is missing', function () {
    $workspace = Workspace::factory()->create();

    // Rewind the workspace to the four fields every tenant had before this set,
    // with phone_number still non-unique, as production has it today.
    $legacy = ['emails', 'phone_number', 'job_title', 'linkedin'];

    DB::table('custom_fields')
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'people')
        ->whereNotIn('code', $legacy)
        ->delete();

    DB::table('custom_fields')
        ->where('tenant_id', $workspace->getKey())
        ->where('code', 'phone_number')
        ->update(['settings' => json_encode(['unique_per_entity_type' => false])]);

    expect(PlatformContactFieldSet::applyTo($workspace->getKey()))
        ->toBe(count(PeopleField::cases()) - count($legacy));

    $fields = contactFields($workspace->getKey());

    expect($fields)->toHaveCount(count(PeopleField::cases()))
        ->and($fields['phone_number']->settings->unique_per_entity_type)->toBeTrue()
        ->and(PlatformContactFieldSet::applyTo($workspace->getKey()))->toBe(0);
});

test('phone number and emails are the only unique fields', function () {
    $workspace = Workspace::factory()->create();

    $unique = array_keys(array_filter(
        contactFields($workspace->getKey()),
        fn (CustomField $field): bool => $field->settings->unique_per_entity_type,
    ));

    sort($unique);

    expect($unique)->toBe(['emails', 'phone_number']);
});

test('marketing opt-in is a four-value select, never a toggle', function () {
    $workspace = Workspace::factory()->create();
    $field = contactFields($workspace->getKey())['marketing_optin'];

    $options = CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();

    expect($field->type)->toBe('select')
        ->and($options)->toBe(['granted', 'declined', 'unknown', 'not_asked'])
        ->and(PeopleField::MARKETING_OPTIN_VALUES)->toBe($options);
});

test('the contact set adds only the three scanned fields to the list columns', function () {
    $workspace = Workspace::factory()->create();

    $alreadyInList = ['emails', 'phone_number', 'job_title', 'linkedin'];

    $addedToList = array_values(array_diff(
        array_keys(array_filter(
            contactFields($workspace->getKey()),
            fn (CustomField $field): bool => $field->settings->visible_in_list,
        )),
        $alreadyInList,
    ));

    sort($addedToList);

    expect($addedToList)->toBe(['dnc', 'next_follow_up', 'preferred_channel']);
});

test('the field set carries the display order it declares', function () {
    $workspace = Workspace::factory()->create();

    foreach (PeopleField::cases() as $case) {
        expect(contactFields($workspace->getKey())[$case->value]->sort_order)
            ->toBe($case->getSortOrder());
    }
});
