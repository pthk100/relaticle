<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Models\Opportunity;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\Sorts\CustomFieldSort;
use App\Support\CurrentWorkspace;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Helpers\WorkspaceCustomField;

mutates(CustomFieldSort::class, CustomFieldFilterSchema::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->actingAs($this->user);
    resolve(CurrentWorkspace::class)->set($this->workspace);
});

function opportunityNamesSortedBy(User $user, string $field, string $direction): array
{
    $names = [];

    RelaticleServer::actingAs($user)
        ->tool(ListOpportunitiesTool::class, ['sort' => ['field' => $field, 'direction' => $direction]])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$names): void {
            $names = array_column(array_column($json->toArray()['items'], 'attributes'), 'name');
            $json->etc();
        });

    return $names;
}

it('sorts opportunities by a custom field in each direction', function (string $direction, array $expected): void {
    $amount = WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'amount');
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Mid'])->saveCustomFieldValue($amount, 50000);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'High'])->saveCustomFieldValue($amount, 100000);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Low'])->saveCustomFieldValue($amount, 1000);

    expect(opportunityNamesSortedBy($this->user, 'amount', $direction))->toBe($expected);
})->with([
    'ascending' => ['asc', ['Low', 'Mid', 'High']],
    'descending' => ['desc', ['High', 'Mid', 'Low']],
]);

it('puts an opportunity without a value last in each direction', function (string $direction, array $expected): void {
    $amount = WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'amount');
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Empty']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'High'])->saveCustomFieldValue($amount, 100000);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Low'])->saveCustomFieldValue($amount, 1000);

    expect(opportunityNamesSortedBy($this->user, 'amount', $direction))->toBe($expected);
})->with([
    'ascending' => ['asc', ['Low', 'High', 'Empty']],
    'descending' => ['desc', ['High', 'Low', 'Empty']],
]);

it('sorts by a custom field created after an earlier sort', function (): void {
    $high = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'High']);
    $low = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Low']);
    opportunityNamesSortedBy($this->user, 'amount', 'asc');

    $score = resolve(CreateCustomField::class)->execute($this->user, ['entity_type' => 'opportunity', 'name' => 'Score', 'code' => 'score', 'type' => 'number']);
    $high->saveCustomFieldValue($score, 90);
    $low->saveCustomFieldValue($score, 10);

    expect(opportunityNamesSortedBy($this->user, 'score', 'asc'))->toBe(['Low', 'High'])
        ->and(opportunityNamesSortedBy($this->user, 'score', 'desc'))->toBe(['High', 'Low']);
});

it('refuses a sort by a custom field that holds a list', function (string $field): void {
    // The allowed list is the field set's sortable codes, in declared order; the
    // list-of-value types (emails, phone_number, linkedin) stay unsortable.
    $allowed = 'name, created_at, updated_at, line_id, wechat_id, kakaotalk_id, '
        .'preferred_channel, job_title, department, seniority, country, preferred_language, '
        .'timezone, lead_source, next_follow_up, account_owner, birthday, marketing_optin, '
        .'dnc, optout_reason, jurisdiction, lawful_basis';

    RelaticleServer::actingAs($this->user)
        ->tool(ListPeopleTool::class, ['sort' => ['field' => $field]])
        ->assertHasErrors(["Requested sort(s) `{$field}` is not allowed. Allowed sort(s) are `{$allowed}`."]);
})->with(['emails', 'phone_number', 'linkedin']);
