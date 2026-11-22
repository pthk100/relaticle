<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ViewErrorBag;
use Relaticle\Flowforge\Board;

mutates(OpportunitiesBoard::class);

beforeEach(function () {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);

    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->stageField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->first();
});

function getOpportunityBoard(): Board
{
    $component = livewire(OpportunitiesBoard::class);

    return $component->instance()->getBoard();
}

it('can render the board page', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertOk();
});

it('displays opportunities in the correct board columns', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $closedWon = $this->stageField->options->firstWhere('name', 'Closed Won');

    $prospectingOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $prospectingOpportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());
    opportunitiesBoardMarkAccepted($prospectingOpportunity);

    $closedWonOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $closedWonOpportunity->saveCustomFieldValue($this->stageField, $closedWon->getKey());
    opportunitiesBoardMarkAccepted($closedWonOpportunity);

    $board = getOpportunityBoard();

    expect($board->getBoardRecords((string) $prospecting->getKey())->pluck('id'))
        ->toContain($prospectingOpportunity->id)
        ->and($board->getBoardRecords((string) $closedWon->getKey())->pluck('id'))
        ->toContain($closedWonOpportunity->id);
});

it('renders the company entity icon on a board card', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    Opportunity::factory()
        ->recycle([$this->user, $this->workspace])
        ->create([
            'name' => 'Acme renewal',
            'company_id' => $company->getKey(),
            'custom_fields' => [
                $this->stageField->code => $prospecting->getKey(),
                OpportunityField::KOC_STAGE->value => opportunitiesBoardAcceptedStage()->getKey(),
            ],
        ]);

    livewire(OpportunitiesBoard::class)
        ->assertSee('Acme Corp')
        ->assertSee(CrmEntity::Company->iconPath(), escape: false);
});

it('orders the company filter alphabetically and chips each option', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Zeta Industries']);
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    $field = null;

    foreach (livewire(OpportunitiesBoard::class)->instance()->getTableFiltersForm()->getComponents(withHidden: true) as $component) {
        foreach ($component->getChildComponentContainers() as $container) {
            foreach ($container->getComponents(withHidden: true) as $child) {
                if ($child instanceof Select && $child->hasRelationship() && $child->getRelationshipName() === 'company') {
                    $field = $child;
                }
            }
        }
    }

    expect($field)->not->toBeNull()
        ->and($field->isHtmlAllowed())->toBeTrue()
        ->and($field->getOptionLabelFromRecord($acme))->toContain(CrmEntity::Company->iconPath());

    $labels = array_values(array_map(
        fn (string $label): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($label))),
        $field->getOptionsFromRelationship(),
    ));

    expect($labels)->toBe(['Acme Corp', 'Zeta Industries']);
});

it('does not show opportunities from other workspaces', function (): void {
    $otherUser = User::factory()->withWorkspace()->create();
    $otherOpportunity = Opportunity::factory()->for($otherUser->currentWorkspace)->create();

    $board = getOpportunityBoard();
    $allRecordIds = collect($this->stageField->options)
        ->flatMap(fn ($opt) => $board->getBoardRecords((string) $opt->getKey()))
        ->pluck('id');

    expect($allRecordIds)->not->toContain($otherOpportunity->id);
});

it('shows the view switcher linking list and board views', function (): void {
    livewire(ListOpportunities::class)
        ->assertSeeHtml(OpportunityResource::getUrl('board'));

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml(OpportunityResource::getUrl('index'));
});

it('redirects the legacy board url to the resource board page', function (): void {
    $this->get(route('filament.app.opportunities-board.redirect', ['tenant' => $this->workspace->slug]))
        ->assertRedirect(OpportunityResource::getUrl('board'));
});

it('moves a card between columns via moveCard', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, (string) $qualification->getKey())
        ->assertDispatched('kanban-card-moved');

    $updatedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($updatedValue)->toBe($qualification->getKey());
});

it('rejects a move to a stage option the board does not own', function (string $targetKind): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    User::factory()->withWorkspace()->create();
    $foreignOptionId = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->where('tenant_id', '!=', $this->workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, $targetId)
        ->assertStatus(422)
        ->assertNotDispatched('kanban-card-moved');

    $storedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($prospecting->getKey());
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('rejects a card created in a stage option the board does not own', function (string $targetKind): void {
    User::factory()->withWorkspace()->create();
    $foreignOptionId = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->where('tenant_id', '!=', $this->workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => $targetId])
        ->set('mountedActions.0.data.name', 'Rejected deal')
        ->call('callMountedAction')
        ->assertStatus(422);

    expect(Opportunity::query()->where('name', 'Rejected deal')->exists())->toBeFalse();
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('creates a card in a stage option the board owns', function (): void {
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => (string) $qualification->getKey()])
        ->set('mountedActions.0.data.name', 'Accepted deal')
        ->call('callMountedAction')
        ->assertOk();

    $opportunity = Opportunity::query()->where('name', 'Accepted deal')->firstOrFail();
    $storedValue = $opportunity->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($qualification->getKey());
});

it('opens the edit action when a card is clicked', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getCardAction())->toBe('edit');

    $component
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $opportunity->id])
        ->assertSet('mountedActions.0.data.name', $opportunity->name);
});

it('labels the contact select point of contact when adding an opportunity to a column', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $page = livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => (string) $prospecting->getKey()])
        ->instance();

    view()->share('errors', new ViewErrorBag);

    expect($page->getSchema($page->getMountedActionSchemaName())->toHtml())->toContain('Point of Contact');
});

/**
 * Sibling of the tasks board badge: "closes today" is a claim about the viewer's
 * calendar. A close date is a plain date, so the stored value is midnight UTC. Read
 * without conversion it still reads as the previous day for anyone far enough east,
 * and the pipeline card lands in the wrong urgency bucket.
 */
it('buckets the close-date badge against the user calendar, not the server clock', function (): void {
    // 23:00 UTC on the 18th is already 08:00 on the 19th in Tokyo.
    $this->travelTo(Date::parse('2026-08-18 23:00:00', 'UTC'));

    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $closeField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::CLOSE_DATE)
        ->first();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $this->stageField->options->firstWhere('name', 'Prospecting')->getKey());
    $opportunity->saveCustomFieldValue($closeField, Date::parse('2026-08-19 00:00:00', 'UTC'));
    opportunitiesBoardMarkAccepted($opportunity);

    livewire(OpportunitiesBoard::class)
        ->assertSee('Closes Today')
        ->assertDontSee('Closes Tomorrow');
});

/**
 * The mirror of the case above, for a viewer west of UTC. A close date is a plain
 * calendar date that the package stores at midnight UTC, so converting it into a
 * negative-offset zone walks it back past midnight and the card reads a day early.
 * the failure the eastern case cannot see, because moving midnight forward stays
 * inside the same day.
 */
it('does not walk a close date back a day for a viewer west of utc', function (): void {
    // 16:00 UTC on the 19th is 09:00 the same morning in Los Angeles.
    $this->travelTo(Date::parse('2026-08-19 16:00:00', 'UTC'));

    $this->user->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $closeField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::CLOSE_DATE)
        ->first();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $this->stageField->options->firstWhere('name', 'Prospecting')->getKey());
    $opportunity->saveCustomFieldValue($closeField, Date::parse('2026-08-19 00:00:00', 'UTC'));
    opportunitiesBoardMarkAccepted($opportunity);

    livewire(OpportunitiesBoard::class)
        ->assertSee('Closes Today')
        ->assertDontSee('Overdue');
});

it('points the navigation link at the view the user opened last', function (): void {
    livewire(OpportunitiesBoard::class)->assertOk();

    expect(OpportunityResource::getNavigationUrl())->toBe(OpportunityResource::getUrl('board'));

    livewire(ListOpportunities::class)->assertOk();

    expect(OpportunityResource::getNavigationUrl())->toBe(OpportunityResource::getUrl('index'));
});

function opportunitiesBoardKocStageField(): CustomField
{
    return CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE)
        ->firstOrFail();
}

function opportunitiesBoardAcceptedStage(): CustomFieldOption
{
    return opportunitiesBoardKocStageField()->options->firstWhere('name', 'sample_pending');
}

function opportunitiesBoardMarkAccepted(Opportunity $opportunity): Opportunity
{
    $opportunity->saveCustomFieldValue(opportunitiesBoardKocStageField(), opportunitiesBoardAcceptedStage()->getKey());

    return $opportunity;
}

/**
 * @return list<string>
 */
function opportunitiesBoardCardIds(Board $board, mixed $columnId): array
{
    return $board->getBoardRecords((string) $columnId)->pluck('id')->all();
}

function opportunitiesBoardCard(
    User $user,
    Workspace $workspace,
    CustomField $stageField,
    CustomFieldOption $stage,
    string $name,
    ?CustomFieldOption $kocStage = null,
): Opportunity {
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => $name]);
    $opportunity->saveCustomFieldValue($stageField, $stage->getKey());

    if ($kocStage instanceof CustomFieldOption) {
        $opportunity->saveCustomFieldValue(opportunitiesBoardKocStageField(), $kocStage->getKey());
    }

    return $opportunity;
}

it('shows collaborations only, hiding pitches and opportunities that never reached the ladder', function (): void {
    $koc = opportunitiesBoardKocStageField();
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $pitch = opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Pitch never answered', $koc->options->firstWhere('name', 'no_response'));
    $sales = opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Sales opportunity with no KOC stage');

    $accepted = collect(['sample_pending', 'shipped', 'delivered', 'fulfilled'])
        ->map(fn (string $option): Opportunity => opportunitiesBoardCard(
            $this->user,
            $this->workspace,
            $this->stageField,
            $prospecting,
            "Accepted {$option}",
            $koc->options->firstWhere('name', $option),
        ));

    expect(opportunitiesBoardCardIds(getOpportunityBoard(), $prospecting->getKey()))
        ->toContain(...$accepted->pluck('id')->all())
        ->not->toContain($pitch->getKey())
        ->not->toContain($sales->getKey());
});

it('brings every hidden row back when accepted only is switched off', function (): void {
    $koc = opportunitiesBoardKocStageField();
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Pitch never answered', $koc->options->firstWhere('name', 'no_response'));
    opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Sales opportunity with no KOC stage');

    foreach (['sample_pending', 'shipped', 'delivered', 'fulfilled'] as $option) {
        opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, "Accepted {$option}", $koc->options->firstWhere('name', $option));
    }

    $component = livewire(OpportunitiesBoard::class);

    expect(opportunitiesBoardCardIds($component->instance()->getBoard(), $prospecting->getKey()))->toHaveCount(4);

    $component->set('tableFilters.accepted_only.isActive', false);

    expect(opportunitiesBoardCardIds($component->instance()->getBoard(), $prospecting->getKey()))->toHaveCount(6);
});

it("offers the workspace's own koc stage options in the stage filter", function (): void {
    $koc = opportunitiesBoardKocStageField();

    $koc->options->firstWhere('name', 'shipped')->update(['name' => 'Shipped to the creator']);

    $filter = getOpportunityBoard()->getBoardFilters()['koc_stage'];

    $stored = CustomFieldOption::query()
        ->where('custom_field_id', $koc->getKey())
        ->pluck('name', 'id')
        ->sortKeys()
        ->all();

    expect($filter)->toBeInstanceOf(SelectFilter::class)
        ->and($filter->getLabel())->toBe('KOC stage')
        ->and(collect($filter->getFormField()->getOptions())->sortKeys()->all())->toBe($stored);
});

it('narrows the board to the koc stage an operator picks', function (): void {
    $koc = opportunitiesBoardKocStageField();
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $shipped = opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Shipped sample', $koc->options->firstWhere('name', 'shipped'));
    opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Delivered sample', $koc->options->firstWhere('name', 'delivered'));

    $component = livewire(OpportunitiesBoard::class);
    $component->set('tableFilters.koc_stage.value', $koc->options->firstWhere('name', 'shipped')->getKey());

    expect(opportunitiesBoardCardIds($component->instance()->getBoard(), $prospecting->getKey()))->toBe([$shipped->getKey()]);
});

it('carries no koc filters when the workspace declares no koc stage field', function (): void {
    $koc = opportunitiesBoardKocStageField();
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $pitch = opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Pitch never answered', $koc->options->firstWhere('name', 'no_response'));

    CustomFieldOption::query()->where('custom_field_id', $koc->getKey())->delete();
    $koc->delete();

    $board = getOpportunityBoard();

    expect($board->getBoardFilters())->not->toHaveKey('koc_stage')
        ->and($board->getBoardFilters())->not->toHaveKey('accepted_only')
        ->and(opportunitiesBoardCardIds($board, $prospecting->getKey()))->toContain($pitch->getKey());

    livewire(OpportunitiesBoard::class)
        ->assertOk()
        ->assertDontSee('KOC stage')
        ->assertDontSee('Accepted collaborations only. Pitches hidden');

    expect(livewire(ListOpportunities::class)->instance()->getTable()->getFilters())
        ->not->toHaveKey('koc_stage')
        ->not->toHaveKey('accepted_only');
});

it('offers both pipeline filters on the opportunity list without hiding sales records by default', function (): void {
    $koc = opportunitiesBoardKocStageField();
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $pitch = opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Pitch never answered', $koc->options->firstWhere('name', 'no_response'));
    $accepted = opportunitiesBoardCard($this->user, $this->workspace, $this->stageField, $prospecting, 'Accepted sample', $koc->options->firstWhere('name', 'sample_pending'));

    $component = livewire(ListOpportunities::class);

    expect($component->instance()->getTable()->getFilters())->toHaveKeys(['koc_stage', 'accepted_only'])
        ->and($component->instance()->getFilteredTableQuery()->pluck('id')->all())
        ->toContain($accepted->getKey())
        ->toContain($pitch->getKey());

    $component->set('tableFilters.accepted_only.isActive', true);

    expect($component->instance()->getFilteredTableQuery()->pluck('id')->all())
        ->toContain($accepted->getKey())
        ->not->toContain($pitch->getKey());
});
