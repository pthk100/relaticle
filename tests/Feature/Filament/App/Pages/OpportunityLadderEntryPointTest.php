<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Facades\Entities;

mutates(ListOpportunities::class);

/**
 * The ladder board is shipped as a sibling page, but a user lands on the list
 * and the only affordance naming the board is a "Switch view" dropdown whose
 * trigger reads "List". These tests pin the list's own way in.
 */
beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    ladderEntryDeclareKoc($this->workspace);
});

function ladderEntryDeclareKoc(Workspace $workspace): CustomField
{
    $rows = CustomFieldRow::build(
        (string) $workspace->getKey(),
        Entities::getEntity(Opportunity::class)?->getAlias() ?? Opportunity::class,
        OpportunityField::KOC_STAGE,
        now()->toImmutable(),
    );

    CustomField::query()->insert($rows['field']);

    if ($rows['options'] !== []) {
        CustomFieldOption::query()->insert($rows['options']);
    }

    return ladderEntryKoc($workspace);
}

function ladderEntryKoc(Workspace $workspace): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE)
        ->where('tenant_id', $workspace->getKey())
        ->firstOrFail();
}

function ladderEntryDropKoc(Workspace $workspace): void
{
    $field = ladderEntryKoc($workspace);

    CustomFieldOption::query()->where('custom_field_id', $field->getKey())->delete();
    $field->delete();
}

it('links the opportunities list straight to the ladder board in one click', function (): void {
    livewire(ListOpportunities::class)
        ->assertActionVisible(TestAction::make('ladder_board'))
        ->assertActionHasUrl(TestAction::make('ladder_board'), OpportunityResource::getUrl('board'))
        ->assertSee('Ladder board');
});

it('offers no ladder board to a workspace that declares no koc stage', function (): void {
    ladderEntryDropKoc($this->workspace);

    livewire(ListOpportunities::class)
        ->assertActionHidden(TestAction::make('ladder_board'))
        ->assertDontSee('Ladder board');
});
