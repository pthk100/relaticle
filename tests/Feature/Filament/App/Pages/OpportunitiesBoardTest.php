<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ViewErrorBag;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\Flowforge\Board;

mutates(OpportunitiesBoard::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);

    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    declareOperationsKocField($this->workspace);

    $this->stageField = opportunitiesBoardField(OpportunityField::STAGE);
});

function getOpportunityBoard(): Board
{
    $component = livewire(OpportunitiesBoard::class);

    return $component->instance()->getBoard();
}

function opportunitiesBoardField(OpportunityField $code, ?Workspace $workspace = null): CustomField
{
    $workspace ??= Filament::getTenant();

    throw_unless($workspace instanceof Workspace, RuntimeException::class, 'No workspace is bound.');

    return CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', $code)
        ->where('tenant_id', $workspace->getKey())
        ->firstOrFail();
}

function declareOperationsKocField(Workspace $workspace): CustomField
{
    return declareOperationsField($workspace, OpportunityField::KOC_STAGE);
}

/**
 * Declares one operations field in the workspace, the way the operations
 * workspace gets it. A workspace that declares no `koc_stage` is the brand
 * tenant's case, so the precondition belongs to the fixture rather than to the
 * surface under test.
 */
function declareOperationsField(Workspace $workspace, OpportunityField $code): CustomField
{
    $declared = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', $code)
        ->where('tenant_id', $workspace->getKey())
        ->first();

    if ($declared instanceof CustomField) {
        return $declared;
    }

    $rows = CustomFieldRow::build(
        (string) $workspace->getKey(),
        Entities::getEntity(Opportunity::class)?->getAlias() ?? Opportunity::class,
        $code,
        now()->toImmutable(),
    );

    CustomField::query()->insert($rows['field']);

    if ($rows['options'] !== []) {
        CustomFieldOption::query()->insert($rows['options']);
    }

    return opportunitiesBoardField($code, $workspace);
}

function opportunitiesBoardDropField(CustomField $field): void
{
    CustomFieldOption::query()->where('custom_field_id', $field->getKey())->delete();
    $field->delete();
}

function opportunitiesBoardOption(CustomField $field, string $name): CustomFieldOption
{
    $option = $field->options->firstWhere('name', $name);

    throw_unless($option instanceof CustomFieldOption, RuntimeException::class, "Unknown option [{$name}] on [{$field->code}].");

    return $option;
}

/**
 * The production shape: the projector writes `koc_stage` and never `stage`, so a
 * fixture that sets both describes a row no surface ever creates.
 */
function opportunitiesBoardKocCard(User $user, Workspace $workspace, string $name, string $kocStage): Opportunity
{
    $field = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => $name]);
    $opportunity->saveCustomFieldValue($field, opportunitiesBoardOption($field, $kocStage)->getKey());

    return $opportunity;
}

function opportunitiesBoardTaskField(TaskField $code): CustomField
{
    $workspace = Filament::getTenant();

    throw_unless($workspace instanceof Workspace, RuntimeException::class, 'No workspace is bound.');

    return CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Task::class)
        ->where('code', $code)
        ->where('tenant_id', $workspace->getKey())
        ->firstOrFail();
}

/**
 * The card as the page composes it, which is what a reader sees: the record the
 * board's own query produced, formatted the way `flowforge::livewire.card`
 * receives it.
 */
function opportunitiesBoardCardHtml(Opportunity $opportunity): string
{
    $board = getOpportunityBoard();

    foreach ($board->getColumnIdentifiers() as $columnId) {
        foreach ($board->getBoardRecords((string) $columnId) as $record) {
            if ($record->getKey() !== $opportunity->getKey()) {
                continue;
            }

            $schema = $board->formatBoardRecord($record)['schema'];

            throw_unless($schema instanceof Schema, RuntimeException::class, 'The board composed no card.');

            return $schema->toHtml();
        }
    }

    throw new RuntimeException("The board shows no card for [{$opportunity->getKey()}].");
}

/**
 * The mint rule puts one open task on a collaboration, dated from the ladder.
 */
function opportunitiesBoardFollowUp(
    User $user,
    Workspace $workspace,
    Opportunity $opportunity,
    ?User $assignee,
    string $dueAt,
    string $status = 'To do',
): Task {
    $task = Task::factory()->recycle([$user, $workspace])->create(['title' => 'chase the post link']);

    if ($assignee instanceof User) {
        $task->assignees()->attach($assignee);
    }

    $task->saveCustomFieldValue(opportunitiesBoardTaskField(TaskField::DUE_DATE), Date::parse($dueAt, 'UTC'));
    $task->saveCustomFieldValue(
        opportunitiesBoardTaskField(TaskField::STATUS),
        opportunitiesBoardOption(opportunitiesBoardTaskField(TaskField::STATUS), $status)->getKey(),
    );
    $opportunity->tasks()->attach($task);

    return $task;
}

function opportunitiesBoardSalesCard(User $user, Workspace $workspace, string $name, string $salesStage): Opportunity
{
    $field = opportunitiesBoardField(OpportunityField::STAGE);

    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => $name]);
    $opportunity->saveCustomFieldValue($field, opportunitiesBoardOption($field, $salesStage)->getKey());

    return $opportunity;
}

/**
 * @return list<string>
 */
function opportunitiesBoardCardIds(Board $board, mixed $columnId): array
{
    return $board->getBoardRecords((string) $columnId)->pluck('id')->all();
}

function opportunitiesBoardColumnLabels(Board $board): array
{
    return array_values(array_map(fn (mixed $label): string => (string) $label, $board->getColumnLabels()));
}

function opportunitiesBoardForeignKocOptionId(Workspace $workspace): string
{
    $otherUser = User::factory()->withWorkspace()->create();
    declareOperationsKocField($otherUser->currentWorkspace);

    return (string) CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE)
        ->where('tenant_id', '!=', $workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();
}

function opportunitiesBoardForeignStageOptionId(Workspace $workspace): string
{
    User::factory()->withWorkspace()->create();

    return (string) CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->where('tenant_id', '!=', $workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();
}

function opportunitiesBoardLadderCardIds(Board $board): array
{
    return collect($board->getColumnIdentifiers())
        ->flatMap(fn (string $columnId): array => opportunitiesBoardCardIds($board, $columnId))
        ->all();
}

it('can render the board page', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertOk();
});

it('draws the koc lanes from the workspace options and puts each collaboration in its own', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $pending = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample pending', 'sample_pending');
    $fulfilled = opportunitiesBoardKocCard($this->user, $this->workspace, 'Olive fulfilled', 'fulfilled');

    $board = getOpportunityBoard();

    // The lane a person reads is the rung's label; the option row keeps the
    // platform's vocabulary, because the projector writes it.
    expect(opportunitiesBoardColumnLabels($board))->toEqualCanonicalizing([
        'Pitch sent',
        'Sample pending',
        'Sample shipped',
        'Sample delivered',
        'Collaboration fulfilled',
    ])->not->toContain('sample_pending')
        ->and(opportunitiesBoardCardIds($board, opportunitiesBoardOption($koc, 'sample_pending')->getKey()))
        ->toContain($pending->getKey())
        ->and(opportunitiesBoardCardIds($board, opportunitiesBoardOption($koc, 'fulfilled')->getKey()))
        ->toContain($fulfilled->getKey());

    livewire(OpportunitiesBoard::class)
        ->assertSee('fulfilled')
        ->assertDontSee('Prospecting');
});

it('carries the open follow-up and its assignee on a collaboration card and no company chip', function (): void {
    $this->travelTo(Date::parse('2026-08-20 09:00:00', 'UTC'));

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $owner = User::factory()->create(['name' => 'Nok Srisuk']);
    $this->workspace->users()->attach($owner, ['role' => 'admin']);

    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);
    $opportunity = Opportunity::factory()
        ->recycle([$this->user, $this->workspace])
        ->create([
            'name' => 'Krill | SKU-1 - @arteereview',
            'company_id' => $company->getKey(),
            'custom_fields' => [
                $koc->code => opportunitiesBoardOption($koc, 'sample_pending')->getKey(),
            ],
        ]);

    opportunitiesBoardFollowUp($this->user, $this->workspace, $opportunity, $owner, '2026-08-21 09:00:00');

    $html = opportunitiesBoardCardHtml($opportunity);

    expect($html)
        ->toContain('fi-badge')
        ->toContain('Nok Srisuk')
        ->toContain('follow-up · Fri 21')
        ->not->toContain('Acme Corp')
        ->not->toContain(CrmEntity::Company->iconPath());
});

it('reads a follow-up past its own day as days late', function (): void {
    $this->travelTo(Date::parse('2026-08-24 09:00:00', 'UTC'));

    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill | SKU-1 - @arteereview', 'sample_pending');
    opportunitiesBoardFollowUp($this->user, $this->workspace, $opportunity, null, '2026-08-21 09:00:00');

    $html = opportunitiesBoardCardHtml($opportunity);

    expect($html)
        ->toContain('follow-up · 3d late')
        ->toContain('fi-color-warning');
});

it('leaves a follow-up the team has closed off the card', function (): void {
    $operator = declareOperationsField($this->workspace, OpportunityField::OPERATOR);

    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill | SKU-1 - @arteereview', 'sample_pending');
    $opportunity->saveCustomFieldValue($operator, 'Somchai');
    opportunitiesBoardFollowUp($this->user, $this->workspace, $opportunity, null, '2026-08-21 09:00:00', 'Done');

    $html = opportunitiesBoardCardHtml($opportunity);

    expect($html)
        ->toContain('Somchai')
        ->not->toContain('follow-up');
});

/**
 * The card's own tag, read out of the page so an assertion about what a line is
 * styled with cannot drift onto another element that happens to carry the class.
 */
function opportunitiesBoardCardTag(string $html, string $attribute): string
{
    preg_match('/<[a-z0-9]+[^>]*'.preg_quote($attribute, '/').'[^>]*>/i', $html, $matches);

    return $matches[0] ?? '';
}

/**
 * The composed record name is the CRM's search surface and is never parsed, so a
 * card leads with the two facts it can read structurally: the creator the
 * follow-up is about, and the product line. The name survives on the tooltip, so
 * hover and copy still answer the search string.
 */
it('leads a collaboration card with the handle and the product line and keeps the composed name on hover', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Pethealer']);
    $contact = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'chanika2626']);
    $sku = declareOperationsField($this->workspace, OpportunityField::PRODUCT_SKU);
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $opportunity = Opportunity::factory()
        ->recycle([$this->user, $this->workspace])
        ->create([
            'name' => 'Pethealer-0371 | PETHEA-ANTARCTIC-KRILL - @chanika2626',
            'company_id' => $company->getKey(),
            'contact_id' => $contact->getKey(),
            'custom_fields' => [
                $koc->code => opportunitiesBoardOption($koc, 'sample_pending')->getKey(),
                $sku->code => 'PETHEA-ANTARCTIC-KRILL',
            ],
        ]);

    $html = livewire(OpportunitiesBoard::class)->html();

    expect(opportunitiesBoardCardTag($html, 'data-card-handle'))
        ->toContain('text-sm')
        ->toContain('font-semibold')
        ->toContain('truncate')
        ->and(opportunitiesBoardCardTag($html, 'data-card-product'))
        ->toContain('truncate')
        ->toContain('whitespace-nowrap')
        ->and($html)
        ->toContain('>@chanika2626<')
        ->toContain('>Pethealer · PETHEA-ANTARCTIC-KRILL<')
        ->toContain('title="Pethealer-0371 | PETHEA-ANTARCTIC-KRILL - @chanika2626"');

    $handleAt = strpos($html, '>@chanika2626<');
    $productAt = strpos($html, '>Pethealer · PETHEA-ANTARCTIC-KRILL<');

    expect($handleAt)->toBeInt()
        ->and($productAt)->toBeInt()
        ->and($handleAt)->toBeLessThan((int) $productAt)
        ->and($opportunity->fresh()?->name)
        ->toBe('Pethealer-0371 | PETHEA-ANTARCTIC-KRILL - @chanika2626');
});

it('shows an em dash where a collaboration card has no handle instead of a blank line', function (): void {
    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill | SKU-1 - @arteereview', 'sample_pending');
    $opportunity->update(['name' => 'No creator wired yet']);

    $html = livewire(OpportunitiesBoard::class)->html();

    expect(opportunitiesBoardCardTag($html, 'data-card-handle'))->not->toBe('')
        ->and($html)->toContain('>—<')
        ->and($html)->not->toContain('data-card-product');
});

it('leaves a sales card titled by its own name, with no handle line', function (): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $html = livewire(OpportunitiesBoard::class)->html();

    expect($html)
        ->toContain('Hand-made deal')
        ->not->toContain('data-card-handle')
        ->not->toContain('data-card-product');
});

it('names the operator mirror on a collaboration that has no open follow-up', function (): void {
    $operator = declareOperationsField($this->workspace, OpportunityField::OPERATOR);

    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill | SKU-1 - @arteereview', 'sample_pending');
    $opportunity->saveCustomFieldValue($operator, 'Somchai');

    $html = opportunitiesBoardCardHtml($opportunity);

    expect($html)
        ->toContain('fi-badge')
        ->toContain('Somchai')
        ->not->toContain('follow-up');
});

it('renders the company entity icon on a sales board card', function (): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    $deal = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');
    $deal->update(['company_id' => $company->getKey()]);

    $html = opportunitiesBoardCardHtml($deal);

    expect($html)
        ->toContain('Acme Corp')
        ->toContain(CrmEntity::Company->iconPath());
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
    $allRecordIds = collect(opportunitiesBoardField(OpportunityField::KOC_STAGE)->options)
        ->flatMap(fn (CustomFieldOption $option) => $board->getBoardRecords((string) $option->getKey()))
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

it('renders no draggable card on the ladder board', function (): void {
    opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getPositionIdentifierAttribute())->toBeNull();

    $component
        ->assertOk()
        ->assertDontSee('x-sortable');
});

it('refuses a drop on the ladder because the platform owns the rung', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, (string) opportunitiesBoardOption($koc, 'shipped')->getKey())
        ->assertNotDispatched('kanban-card-moved');

    $fresh = $opportunity->fresh();

    expect($fresh->customFieldValues()->where('custom_field_id', $koc->getKey())->value($koc->getValueColumn()))
        ->toBe(opportunitiesBoardOption($koc, 'sample_pending')->getKey())
        ->and($fresh->order_column)->toBeNull()
        ->and($fresh->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->exists())
        ->toBeFalse();
});

it('orders a ladder lane by the last update because a rung carries no position', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $older = opportunitiesBoardKocCard($this->user, $this->workspace, 'Older sample', 'delivered');
    $newer = opportunitiesBoardKocCard($this->user, $this->workspace, 'Newer sample', 'delivered');

    $older->forceFill(['updated_at' => Date::parse('2026-01-01 09:00:00', 'UTC')])->saveQuietly();
    $newer->forceFill(['updated_at' => Date::parse('2026-01-02 09:00:00', 'UTC')])->saveQuietly();

    $lane = opportunitiesBoardCardIds(getOpportunityBoard(), opportunitiesBoardOption($koc, 'delivered')->getKey());

    expect($lane)->toBe([$newer->getKey(), $older->getKey()]);
});

it('rejects a sales drop to a stage the board does not own', function (string $targetKind): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $opportunity = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $targetId = $targetKind === 'foreign'
        ? opportunitiesBoardForeignStageOptionId($this->workspace)
        : 'not-an-option';

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, $targetId)
        ->assertStatus(422)
        ->assertNotDispatched('kanban-card-moved');

    expect($opportunity->fresh()->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value($this->stageField->getValueColumn()))
        ->toBe(opportunitiesBoardOption($this->stageField, 'Prospecting')->getKey());
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('keeps the sales board draggable and persists a drop into another stage', function (): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $deal = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');
    $qualification = opportunitiesBoardOption($this->stageField, 'Qualification');

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getPositionIdentifierAttribute())->toBe('order_column');

    $component
        ->assertSee('x-sortable-group', escape: false)
        ->assertSee('x-sortable-item', escape: false)
        ->call('moveCard', (string) $deal->getKey(), (string) $qualification->getKey())
        ->assertDispatched('kanban-card-moved');

    $fresh = $deal->fresh();

    expect($fresh->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value($this->stageField->getValueColumn()))
        ->toBe($qualification->getKey())
        ->and($fresh->order_column)->not->toBeNull();
});

it('rejects a card created in a koc option the board does not own', function (string $targetKind): void {
    $foreignOptionId = opportunitiesBoardForeignKocOptionId($this->workspace);

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => $targetId])
        ->set('mountedActions.0.data.name', 'Rejected collaboration')
        ->call('callMountedAction')
        ->assertStatus(422);

    expect(Opportunity::query()->where('name', 'Rejected collaboration')->exists())->toBeFalse();
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('creates a card in a koc lane the board owns', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);
    $shipped = opportunitiesBoardOption($koc, 'shipped');

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => (string) $shipped->getKey()])
        ->set('mountedActions.0.data.name', 'Accepted collaboration')
        ->call('callMountedAction')
        ->assertOk();

    $opportunity = Opportunity::query()->where('name', 'Accepted collaboration')->firstOrFail();

    expect($opportunity->customFieldValues()->where('custom_field_id', $koc->getKey())->value($koc->getValueColumn()))
        ->toBe($shipped->getKey())
        ->and($opportunity->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value($this->stageField->getValueColumn()))
        ->toBeNull()
        ->and($opportunity->order_column)->toBeNull();
});

it('opens the edit action when a card is clicked', function (): void {
    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getCardAction())->toBe('edit');

    $component
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $opportunity->id])
        ->assertSet('mountedActions.0.data.name', $opportunity->name);
});

it('labels the contact select point of contact when adding an opportunity to a column', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $page = livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => (string) opportunitiesBoardOption($koc, 'shipped')->getKey()])
        ->instance();

    view()->share('errors', new ViewErrorBag);

    expect($page->getSchema($page->getMountedActionSchemaName())->toHtml())->toContain('Point of Contact');
});

it('leaves the koc stage out of the edit slide-over and says where it comes from', function (): void {
    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    $page = livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $opportunity->id])
        ->instance();

    view()->share('errors', new ViewErrorBag);

    $html = $page->getSchema($page->getMountedActionSchemaName())->toHtml();

    expect($html)
        ->not->toContain('KOC Stage')
        ->toContain('Stage is mirrored from the KOC platform and is not editable here.');
});

it('prints no provenance line where the workspace declares no ladder', function (): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $deal = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $page = livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $deal->getKey()])
        ->instance();

    view()->share('errors', new ViewErrorBag);

    expect($page->getSchema($page->getMountedActionSchemaName())->toHtml())
        ->not->toContain('Stage is mirrored');
});

it('leaves the koc stage out of the quick create form because the column sets it', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $page = livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => (string) opportunitiesBoardOption($koc, 'shipped')->getKey()])
        ->instance();

    view()->share('errors', new ViewErrorBag);

    expect($page->getSchema($page->getMountedActionSchemaName())->toHtml())
        ->not->toContain('KOC Stage');
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

    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $closeField = opportunitiesBoardField(OpportunityField::CLOSE_DATE);

    $deal = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');
    $deal->saveCustomFieldValue($closeField, Date::parse('2026-08-19 00:00:00', 'UTC'));

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

    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $closeField = opportunitiesBoardField(OpportunityField::CLOSE_DATE);

    $deal = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');
    $deal->saveCustomFieldValue($closeField, Date::parse('2026-08-19 00:00:00', 'UTC'));

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

it('hides pitches by default and shows them under no response when accepted only is off', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);
    $pitch = opportunitiesBoardKocCard($this->user, $this->workspace, 'Pitch never answered', 'no_response');

    $accepted = collect(['sample_pending', 'shipped', 'delivered', 'fulfilled'])
        ->map(fn (string $stage): Opportunity => opportunitiesBoardKocCard(
            $this->user,
            $this->workspace,
            "Accepted {$stage}",
            $stage,
        ));

    $component = livewire(OpportunitiesBoard::class);
    $board = $component->instance()->getBoard();

    expect(opportunitiesBoardCardIds($board, opportunitiesBoardOption($koc, 'no_response')->getKey()))
        ->toBe([])
        ->and(collect(opportunitiesBoardLadderCardIds($board))->sort()->values()->all())
        ->toBe($accepted->pluck('id')->sort()->values()->all());

    $component->set('tableFilters.accepted_only.isActive', false);

    $board = $component->instance()->getBoard();
    $pitchLane = opportunitiesBoardCardIds($board, opportunitiesBoardOption($koc, 'no_response')->getKey());

    expect($pitchLane)->toBe([$pitch->getKey()])
        ->and(opportunitiesBoardLadderCardIds($board))->toHaveCount(5);
});

it('says a filtered-out pitch is hidden rather than absent', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $pitch = opportunitiesBoardKocCard($this->user, $this->workspace, 'Pitch never answered', 'no_response');

    $component = livewire(OpportunitiesBoard::class);

    expect(opportunitiesBoardCardIds($component->instance()->getBoard(), opportunitiesBoardOption($koc, 'no_response')->getKey()))
        ->toBe([]);

    $component
        ->assertSee('1 collaboration hidden by the filter')
        ->assertDontSee('No collaborations yet.');

    $component->set('tableFilters.accepted_only.isActive', false);

    $component
        ->assertSee($pitch->name)
        ->assertDontSee('hidden by the filter');
});

it('marks the no response column with the pitches the toggle is hiding', function (): void {
    $pitch = opportunitiesBoardKocCard($this->user, $this->workspace, 'Pitch never answered', 'no_response');

    $component = livewire(OpportunitiesBoard::class);

    $component
        ->assertSee('+1 pitch hidden')
        ->assertDontSee($pitch->name);

    $component->set('tableFilters.accepted_only.isActive', false);

    $component
        ->assertSee($pitch->name)
        ->assertDontSee('pitch hidden');
});

it('reads an empty workspace as empty rather than as filtered', function (): void {
    $component = livewire(OpportunitiesBoard::class);

    $component
        ->assertSee('No collaborations yet.')
        ->assertSee('Accepted collaborations only')
        ->assertDontSee('hidden by the filter')
        ->assertDontSee('flowforge-column');
});

it('keeps a sales opportunity off the koc board whether the toggle is on or off', function (): void {
    $sales = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class);

    expect(opportunitiesBoardLadderCardIds($component->instance()->getBoard()))
        ->not->toContain($sales->getKey());

    $component->set('tableFilters.accepted_only.isActive', false);

    expect(opportunitiesBoardLadderCardIds($component->instance()->getBoard()))
        ->not->toContain($sales->getKey());
});

it('scopes the koc board query to rows holding a stage, whatever the toggle says', function (): void {
    $collaboration = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill delivered', 'delivered');
    $sales = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getFilteredTableQuery()->pluck('opportunities.id')->all())
        ->toContain($collaboration->getKey())
        ->not->toContain($sales->getKey());

    $component->set('tableFilters.accepted_only.isActive', false);

    expect($component->instance()->getFilteredTableQuery()->pluck('opportunities.id')->all())
        ->toContain($collaboration->getKey())
        ->not->toContain($sales->getKey());
});

it('explains on the board that it shows collaborations only', function (): void {
    $caption = __('filament/pages/boards.opportunities.koc_caption');

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoardCaption())->toBe($caption);

    $component->assertSee($caption);
});

/**
 * The caption used to be the page's Filament subheading, which the board toolbar
 * renders in the same row as the view switcher and the search field: at 1440 it
 * started 16px right of the chip, wrapped to two lines and ran into the search
 * field. The board header owns the line now, so the page has no subheading left
 * for the toolbar to render.
 */
it('gives the caption its own line under the board name instead of the toolbar row', function (): void {
    $component = livewire(OpportunitiesBoard::class);
    $html = $component->html();

    $name = strpos($html, 'fi-board-name');
    $caption = strpos($html, 'fi-board-caption');

    expect($component->instance()->getSubheading())->toBeNull()
        ->and($name)->not->toBeFalse()
        ->and($caption)->not->toBeFalse()
        ->and($caption)->toBeGreaterThan($name);
});

it('names the ladder board, because the topbar title names the resource', function (): void {
    $page = livewire(OpportunitiesBoard::class)->instance();

    expect($page->getBoardName())->toBe(__('filament/pages/boards.opportunities.board_name.ladder'))
        ->and($page->getSubheading())->toBeNull();
});

it('names a sales board after its pipeline and gives it no ladder caption', function (): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $page = livewire(OpportunitiesBoard::class)->instance();

    expect($page->getBoardName())->toBe(__('filament/pages/boards.opportunities.board_name.pipeline'))
        ->and($page->getBoardCaption())->toBeNull();
});

/**
 * Five lanes carrying the same sentence say nothing a reader can act on, and the
 * archive-box glyph beside an empty lane reads as "archive this lane" on a board
 * whose first verb is drag.
 */
it('says what fills each empty lane instead of repeating one sentence and an archive box', function (): void {
    opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    livewire(OpportunitiesBoard::class)
        ->assertDontSee('No opportunities in this column')
        ->assertSee(__('filament/pages/boards.opportunities.empty.lane.no_response'))
        ->assertSee(__('filament/pages/boards.opportunities.empty.lane.shipped'))
        ->assertSee(__('filament/pages/boards.opportunities.empty.lane.delivered'))
        ->assertSee(__('filament/pages/boards.opportunities.empty.lane.fulfilled'))
        ->assertDontSeeHtml('m20.25 7.5-.625 10.632a2.25');
});

it('lets a lane a workspace renamed say what fills it in that lane\'s own words', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');
    opportunitiesBoardOption($koc, 'shipped')->update(['name' => 'Shipped to the creator']);

    livewire(OpportunitiesBoard::class)
        ->assertSee('A card appears in Shipped to the creator when it reaches this stage.');
});

it('says what the toggle does in the caption, not only what the board is', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertSee('so pitches are hidden unless you show them');
});

it("offers the workspace's own koc stage options in the stage filter", function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $koc->options->firstWhere('name', 'shipped')->update(['name' => 'Shipped to the creator']);

    $filter = getOpportunityBoard()->getBoardFilters()['koc_stage'];

    $options = collect($filter->getFormField()->getOptions());

    expect($filter)->toBeInstanceOf(SelectFilter::class)
        ->and($filter->getLabel())->toBe('KOC stage')
        // The operator renamed a rung, so the workspace's own words win.
        ->and($options[opportunitiesBoardOption($koc, 'Shipped to the creator')->getKey()])->toBe('Shipped to the creator')
        // A rung nobody renamed reads as its label, never its machine value.
        ->and($options[opportunitiesBoardOption($koc, 'sample_pending')->getKey()])->toBe('Sample pending')
        ->and($options->values()->all())->not->toContain('sample_pending');
});

it('narrows the board to the koc stage an operator picks', function (): void {
    $koc = opportunitiesBoardField(OpportunityField::KOC_STAGE);

    $shipped = opportunitiesBoardKocCard($this->user, $this->workspace, 'Shipped sample', 'shipped');
    opportunitiesBoardKocCard($this->user, $this->workspace, 'Delivered sample', 'delivered');

    $component = livewire(OpportunitiesBoard::class);
    $component->set('tableFilters.koc_stage.value', (string) opportunitiesBoardOption($koc, 'shipped')->getKey());

    expect(opportunitiesBoardLadderCardIds($component->instance()->getBoard()))->toBe([$shipped->getKey()]);
});

it('keeps the sales board and drops both koc filters where the workspace declares no koc stage field', function (): void {
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    $prospecting = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $board = getOpportunityBoard();

    expect(opportunitiesBoardColumnLabels($board))->toEqualCanonicalizing(
        $this->stageField->options->pluck('name')->all(),
    )->and(opportunitiesBoardCardIds($board, opportunitiesBoardOption($this->stageField, 'Prospecting')->getKey()))
        ->toContain($prospecting->getKey())
        ->and($board->getBoardFilters())->not->toHaveKey('koc_stage')
        ->and($board->getBoardFilters())->not->toHaveKey('accepted_only');

    livewire(OpportunitiesBoard::class)
        ->assertOk()
        ->assertDontSee('KOC stage')
        ->assertDontSee('Accepted collaborations only')
        ->assertDontSee('pitch hidden')
        ->assertDontSee(__('filament/pages/boards.opportunities.koc_caption'));

    expect(livewire(ListOpportunities::class)->instance()->getTable()->getFilters())
        ->not->toHaveKey('koc_stage')
        ->not->toHaveKey('accepted_only');
});

it('opens the koc board in a workspace that declares no sales stage field', function (): void {
    opportunitiesBoardDropField($this->stageField);

    expect(OpportunitiesBoard::canAccess())->toBeTrue();

    $opportunity = opportunitiesBoardKocCard($this->user, $this->workspace, 'Krill sample', 'delivered');

    $board = getOpportunityBoard();

    expect(opportunitiesBoardColumnLabels($board))->toEqualCanonicalizing([
        'Pitch sent',
        'Sample pending',
        'Sample shipped',
        'Sample delivered',
        'Collaboration fulfilled',
    ])->and(opportunitiesBoardCardIds($board, opportunitiesBoardOption(opportunitiesBoardField(OpportunityField::KOC_STAGE), 'delivered')->getKey()))
        ->toContain($opportunity->getKey());
});

it('refuses the board when the workspace declares neither ladder', function (): void {
    opportunitiesBoardDropField($this->stageField);
    opportunitiesBoardDropField(opportunitiesBoardField(OpportunityField::KOC_STAGE));

    expect(OpportunitiesBoard::canAccess())->toBeFalse();
});

it('offers both pipeline filters on the opportunity list without hiding sales records by default', function (): void {
    $pitch = opportunitiesBoardKocCard($this->user, $this->workspace, 'Pitch never answered', 'no_response');
    $accepted = opportunitiesBoardKocCard($this->user, $this->workspace, 'Accepted sample', 'sample_pending');

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

it('hides a record without a koc stage from the list while accepted only is on', function (): void {
    $sales = opportunitiesBoardSalesCard($this->user, $this->workspace, 'Hand-made deal', 'Prospecting');

    $component = livewire(ListOpportunities::class);

    expect($component->instance()->getFilteredTableQuery()->pluck('id')->all())->toContain($sales->getKey());

    $component->set('tableFilters.accepted_only.isActive', true);

    expect($component->instance()->getFilteredTableQuery()->pluck('id')->all())->not->toContain($sales->getKey());
});
