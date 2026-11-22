<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Column;

mutates([ListOpportunities::class, OpportunitiesBoard::class, ViewOpportunity::class]);

/**
 * The platform writes `koc_stage` in its own machine vocabulary
 * (`sample_pending`, `fulfilled`, …) and the option rows carry that vocabulary
 * as their name, so the label a person reads has to be mapped at render time.
 * The stored name must not move: the projector writes it and the CRM resolves
 * it back to an option id.
 */
beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);

    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    kocLabelsDeclare($this->workspace);
});

function kocLabelsDeclare(Workspace $workspace): CustomField
{
    $declared = kocLabelsField($workspace);

    if ($declared instanceof CustomField) {
        return $declared;
    }

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

    return kocLabelsField($workspace);
}

function kocLabelsField(Workspace $workspace): ?CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE->value)
        ->where('tenant_id', $workspace->getKey())
        ->first();
}

function kocLabelsOption(CustomField $field, string $name): CustomFieldOption
{
    $option = $field->options->firstWhere('name', $name);

    throw_unless($option instanceof CustomFieldOption, RuntimeException::class, "Unknown option [{$name}].");

    return $option;
}

function kocLabelsCard(User $user, Workspace $workspace, string $name, string $stage): Opportunity
{
    $field = kocLabelsDeclare($workspace);

    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => $name]);
    $opportunity->saveCustomFieldValue($field, kocLabelsOption($field, $stage)->getKey());

    return $opportunity;
}

it('names the stored stage a person reads in the opportunities list', function (): void {
    kocLabelsCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    livewire(ListOpportunities::class)
        ->assertOk()
        ->assertSee('Sample pending')
        ->assertDontSee('sample_pending');
});

it('names the stored stage on the opportunity itself', function (): void {
    $opportunity = kocLabelsCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');

    livewire(ViewOpportunity::class, ['record' => $opportunity->getRouteKey()])
        ->assertOk()
        ->assertSee('Sample pending')
        ->assertDontSee('sample_pending');
});

it('names the ladder lanes and their columns with the same labels', function (): void {
    kocLabelsCard($this->user, $this->workspace, 'Krill sample', 'sample_pending');
    kocLabelsCard($this->user, $this->workspace, 'Olive fulfilled', 'fulfilled');

    $board = livewire(OpportunitiesBoard::class)->instance()->getBoard();

    expect(kocLabelsLaneLabels($board))->toEqualCanonicalizing([
        'Pitch sent',
        'Sample pending',
        'Sample shipped',
        'Sample delivered',
        'Collaboration fulfilled',
    ]);

    livewire(OpportunitiesBoard::class)
        ->assertOk()
        ->assertSee('Sample pending')
        ->assertSee('Collaboration fulfilled');

    // The board serialises each option (its `name` included) into the page's own
    // data, so the assertion is about the text a person reads: the rendered
    // markup, with every attribute and its payload stripped. `shipped`,
    // `delivered` and `fulfilled` are words inside their own labels, so only the
    // two values whose label replaces them can be asserted this way; the lane
    // list above pins all five.
    expect(kocLabelsRenderedText(livewire(OpportunitiesBoard::class)->html()))
        ->not->toContain('sample_pending')
        ->not->toContain('no_response');
});

function kocLabelsRenderedText(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

it('offers the ladder labels in the stage filter, keyed by the stored option', function (): void {
    $field = kocLabelsField($this->workspace);

    throw_unless($field instanceof CustomField, RuntimeException::class, 'No koc stage field.');

    $filter = livewire(ListOpportunities::class)->instance()->getTable()->getFilters()['koc_stage'];

    $options = $filter->getFormField()->getOptions();

    expect(array_keys($options))->toBe($field->options->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())
        ->and(array_values($options))->toBe([
            'Pitch sent',
            'Sample pending',
            'Sample shipped',
            'Sample delivered',
            'Collaboration fulfilled',
        ]);
});

it('narrows the list to the rung a person picks from the filtered labels', function (): void {
    $shipped = kocLabelsCard($this->user, $this->workspace, 'Shipped sample', 'shipped');
    $pending = kocLabelsCard($this->user, $this->workspace, 'Pending sample', 'sample_pending');

    $field = kocLabelsField($this->workspace);

    throw_unless($field instanceof CustomField, RuntimeException::class, 'No koc stage field.');

    $component = livewire(ListOpportunities::class);
    $component->set('tableFilters.koc_stage.value', (string) kocLabelsOption($field, 'shipped')->getKey());

    expect($component->instance()->getFilteredTableQuery()->pluck('id')->all())
        ->toBe([$shipped->getKey()])
        ->not->toContain($pending->getKey());
});

it('keeps the platform vocabulary in the stored options while a person reads labels', function (): void {
    $field = kocLabelsField($this->workspace);

    throw_unless($field instanceof CustomField, RuntimeException::class, 'No koc stage field.');

    // The projector posts these names and the API resolves each back to an
    // option id, so a label may never replace one.
    expect($field->options->pluck('name')->all())->toBe(OpportunityField::KOC_STAGE_VALUES)
        ->and(OpportunityField::kocStageLabels())->toBe([
            'no_response' => 'Pitch sent',
            'sample_pending' => 'Sample pending',
            'shipped' => 'Sample shipped',
            'delivered' => 'Sample delivered',
            'fulfilled' => 'Collaboration fulfilled',
        ]);
});

function kocLabelsLaneLabels(Board $board): array
{
    return collect($board->getColumns())->map(fn (Column $column): string => $column->getLabel())->all();
}
