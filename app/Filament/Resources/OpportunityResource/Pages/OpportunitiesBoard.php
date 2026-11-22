<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Filament\Components\Forms\RecordSelect;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Components\Tables\Filters\RecordSelectFilter;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Filters\KocPipelineFilters;
use App\Filament\Resources\OpportunityResource\Forms\OpportunityForm;
use App\Filament\Resources\OpportunityResource\OpportunityBoard;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use League\CommonMark\Exception\InvalidArgumentException;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardResourcePage;
use Relaticle\Flowforge\Column;
use Relaticle\Flowforge\Components\CardFlex;
use Throwable;

final class OpportunitiesBoard extends BoardResourcePage
{
    use HasViewSwitcher;

    protected static string $resource = OpportunityResource::class;

    protected string $view = 'filament.pages.opportunities-board';

    /** @var array<string, int>|null */
    private ?array $hiddenCards = null;

    /**
     * The collaboration ladder, in a workspace that declares one. In a brand
     * tenant's workspace this is the sales stage, and the board is its pipeline.
     */
    private ?CustomField $columnField = null;

    public function getTitle(): string
    {
        return __('filament/pages/boards.opportunities.title');
    }

    /**
     * A row that is silently absent from a board is the failure mode this
     * project keeps paying for, so the ladder board says what it holds.
     */
    public function getSubheading(): ?string
    {
        return $this->isKocLadder() ? (string) __('filament/pages/boards.opportunities.koc_caption') : null;
    }

    /**
     * An empty lane and an empty workspace are different facts, so the column says
     * how many rows its own filter is holding back.
     */
    public function hiddenCardsInColumn(string $columnId): int
    {
        if (! $this->isKocLadder()) {
            return 0;
        }

        $board = $this->getBoard();

        if (! $board instanceof OpportunityBoard) {
            return 0;
        }

        $this->hiddenCards ??= $board->hiddenRecordCounts();

        return $this->hiddenCards[$columnId] ?? 0;
    }

    /**
     * The ladder keeps a pitch in no response until the creator says yes, so that
     * column is the only one the toggle can empty. It says how many it is hiding.
     */
    public function hiddenPitchCount(string $columnId): int
    {
        if (! $this->isKocLadder()) {
            return 0;
        }

        $pitch = $this->columnField()?->options->firstWhere('name', OpportunityCustomField::KOC_STAGE_PITCH);

        if (! $pitch instanceof CustomFieldOption || (string) $pitch->getKey() !== $columnId) {
            return 0;
        }

        return $this->hiddenCardsInColumn($columnId);
    }

    /**
     * The empty block answers the board's own unfiltered rows, so a filter hiding
     * the only pitch can never pass itself off as a workspace with no work.
     */
    public function showsEmptyLadder(): bool
    {
        if (! $this->isKocLadder()) {
            return false;
        }

        $query = $this->getBoard()->getQuery();

        return $query instanceof Builder && ! (clone $query)->exists();
    }

    protected function makeBoard(): OpportunityBoard
    {
        return OpportunityBoard::make($this)->query(fn (): ?Builder => $this->getBoardQuery());
    }

    public function board(Board $board): Board
    {
        $columnField = $this->columnField();

        abort_unless($columnField instanceof CustomField, 403);

        return $board
            ->query($this->boardQuery($columnField))
            ->recordTitleAttribute('name')
            ->columnIdentifier($columnField->getValueColumn())
            ->unless($this->isKocLadder(), fn (Board $board): Board => $board->positionIdentifier('order_column'))
            ->searchable(['name'])
            ->columns($this->getColumns())
            ->cardSchema($this->cardSchema())
            ->columnActions($this->columnActions())
            ->cardAction('edit')
            ->cardActions($this->cardActions())
            ->filters($this->boardFilters())
            ->filtersFormWidth(Width::Medium)
            ->headerToolbar();
    }

    /**
     * The board's rows are the ones holding a value in its column field, so a
     * sales opportunity is absent from the ladder board however the filters sit.
     *
     * @return Builder<Opportunity>
     */
    private function boardQuery(CustomField $columnField): Builder
    {
        $valueColumn = $columnField->getValueColumn();

        $query = Opportunity::query()
            ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($columnField): void {
                $join->on('opportunities.id', '=', 'cfv.entity_id')
                    ->where('cfv.custom_field_id', '=', $columnField->getKey());
            })
            ->select('opportunities.*', 'cfv.'.$valueColumn)
            ->with($this->cardRelations());

        if (! $this->isKocLadder()) {
            return $query;
        }

        return $query
            ->whereNotNull('cfv.'.$valueColumn)
            ->orderByDesc('opportunities.updated_at');
    }

    /**
     * A collaboration card names the follow-up and the operator, so both load with
     * the row. A sales card keeps the brand chip it has always carried.
     *
     * @return list<string>
     */
    private function cardRelations(): array
    {
        return $this->isKocLadder()
            ? ['tasks.assignees', 'tasks.customFieldValues.customField', 'customFieldValues.customField']
            : ['company.media', 'contact'];
    }

    /**
     * @return list<BaseFilter>
     */
    private function boardFilters(): array
    {
        return [
            RecordSelectFilter::make('companies')
                ->label(__('filament/pages/boards.opportunities.filters.company'))
                ->relationship('company', 'name')
                ->searchable()
                ->preload()
                ->multiple(),
            RecordSelectFilter::make('contacts')
                ->label(__('filament/pages/boards.opportunities.filters.contact'))
                ->relationship('contact', 'name')
                ->searchable()
                ->preload()
                ->multiple(),
            ...KocPipelineFilters::all(acceptedOnlyByDefault: true),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function columnActions(): array
    {
        return [
            CreateAction::make()
                ->authorize(fn (): bool => Gate::allows('create', Opportunity::class))
                ->label(__('filament/pages/boards.opportunities.actions.add'))
                ->icon('heroicon-o-plus')
                ->iconButton()
                ->modalWidth(Width::Large)
                ->slideOver(false)
                ->model(Opportunity::class)
                ->schema($this->quickCreateForm(...))
                ->using($this->createCard(...)),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function cardActions(): array
    {
        return [
            Action::make('edit')
                ->authorize(fn (?Opportunity $record): bool => $record instanceof Opportunity && Gate::allows('update', $record))
                ->label(__('filament/pages/boards.opportunities.actions.edit'))
                ->slideOver()
                ->modalWidth(Width::ExtraLarge)
                ->icon('heroicon-o-pencil-square')
                ->schema(OpportunityForm::get(...))
                ->fillForm(fn (Opportunity $record): array => [
                    'name' => $record->name,
                    'company_id' => $record->company_id,
                    'contact_id' => $record->contact_id,
                ])
                ->action(function (Opportunity $record, array $data): void {
                    $record->update($data);
                }),
            Action::make('delete')
                ->authorize(fn (?Opportunity $record): bool => $record instanceof Opportunity && Gate::allows('delete', $record))
                ->label(__('filament/pages/boards.opportunities.actions.delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (Opportunity $record): void {
                    $record->delete();
                }),
        ];
    }

    /**
     * A collaboration card names the two facts the operator acts on; a sales card
     * keeps the brand and the deal's own values, because its title carries neither.
     */
    private function cardSchema(): Closure
    {
        return function (Schema $schema): Schema {
            if ($this->isKocLadder()) {
                return $schema->components([
                    CardFlex::make([
                        $this->ownerBadge(),
                        $this->followUpBadge(),
                    ])->align('center'),
                ]);
            }

            return $schema->components($this->salesCardRows());
        };
    }

    /**
     * @return array<int, CardFlex>
     */
    private function salesCardRows(): array
    {
        $customFields = CustomFields::infolist()
            ->forModel(Opportunity::class)
            ->only([OpportunityCustomField::AMOUNT, OpportunityCustomField::CLOSE_DATE])
            ->hiddenLabels()
            ->visibleWhenFilled()
            ->withoutSections()
            ->values()
            ->keyBy(fn (mixed $field): string => $field->getName());

        $amountField = $customFields->get('custom_fields.'.OpportunityCustomField::AMOUNT->value)
            ?->visible(fn (?string $state): bool => filled($state))
            ->badge()
            ->color('success')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->grow(false)
            ->hiddenLabel();

        $closeDateField = $customFields->get('custom_fields.'.OpportunityCustomField::CLOSE_DATE->value)
            ?->visible(fn (?string $state): bool => filled($state))
            ->badge()
            ->color('gray')
            ->icon(Heroicon::OutlinedCalendar)
            ->grow(false)
            ->hiddenLabel()
            ->formatStateUsing(fn (?string $state): string => $this->formatCloseDateBadge($state));

        return [
            CardFlex::make([
                RecordChipEntry::make('company.name')
                    ->chipSize('sm')
                    ->hiddenLabel()
                    ->visible(fn (?string $state): bool => filled($state))
                    ->color('gray')
                    ->size(TextSize::ExtraSmall)
                    ->grow(),
            ]),
            CardFlex::make([
                $amountField,
                $closeDateField,
            ])->align('center'),
        ];
    }

    /**
     * The open task's assignee is who gets nudged, so that name wins over the
     * operator mirror the platform wrote.
     */
    private function ownerBadge(): TextEntry
    {
        return TextEntry::make('owner_badge')
            ->state(fn (Opportunity $record): ?string => $this->ownerName($record))
            ->visible(fn (?string $state): bool => filled($state))
            ->badge()
            ->color('gray')
            ->icon(Heroicon::OutlinedUser)
            ->hiddenLabel()
            ->grow(false)
            ->size(TextSize::ExtraSmall);
    }

    private function followUpBadge(): TextEntry
    {
        return TextEntry::make('follow_up_badge')
            ->state(fn (Opportunity $record): ?string => $this->followUp($record)['label'] ?? null)
            ->visible(fn (?string $state): bool => filled($state))
            ->badge()
            ->color(fn (Opportunity $record): string => ($this->followUp($record)['late'] ?? false) ? 'warning' : 'gray')
            ->hiddenLabel()
            ->grow(false)
            ->size(TextSize::ExtraSmall);
    }

    private function ownerName(Opportunity $record): ?string
    {
        $assignee = $this->openFollowUp($record)?->assignees->first()?->name;

        if (filled($assignee)) {
            return (string) $assignee;
        }

        $field = $this->operatorField();

        if (! $field instanceof CustomField) {
            return null;
        }

        $operator = $record->customFieldValues->firstWhere('custom_field_id', $field->getKey())?->getValue();

        return is_string($operator) && $operator !== '' ? $operator : null;
    }

    /**
     * One open task per collaboration is the mint rule, so the earliest due date
     * is the one the card names.
     */
    private function openFollowUp(Opportunity $record): ?Task
    {
        $statusField = $this->taskFields()['status'];
        $doneOptionId = $statusField instanceof CustomField
            ? $statusField->options->firstWhere('name', TaskCustomField::DONE_STATUS)?->getKey()
            : null;

        return $record->tasks
            ->reject(function (Task $task) use ($statusField, $doneOptionId): bool {
                if (! $statusField instanceof CustomField || $doneOptionId === null) {
                    return false;
                }

                $status = $task->customFieldValues->firstWhere('custom_field_id', $statusField->getKey());

                return (string) $status?->getValue() === (string) $doneOptionId;
            })
            ->sortBy(fn (Task $task): int => $this->taskDueDate($task)?->getTimestamp() ?? PHP_INT_MAX)
            ->first();
    }

    /**
     * @return array{label: string, late: bool}|null
     */
    private function followUp(Opportunity $record): ?array
    {
        $due = $this->taskDueDate($this->openFollowUp($record));

        if (! $due instanceof CarbonImmutable) {
            return null;
        }

        $timezone = FilamentTimezone::get();
        $dueDay = $due->setTimezone($timezone)->startOfDay();
        $today = Date::now($timezone)->startOfDay();

        if ($dueDay->lessThan($today)) {
            return [
                'label' => __('filament/pages/boards.opportunities.card.follow_up_late', [
                    'days' => (int) round($today->diffInDays($dueDay, true)),
                ]),
                'late' => true,
            ];
        }

        return [
            'label' => __('filament/pages/boards.opportunities.card.follow_up', ['date' => $dueDay->format('D j')]),
            'late' => false,
        ];
    }

    private function taskDueDate(?Task $task): ?CarbonImmutable
    {
        if (! $task instanceof Task) {
            return null;
        }

        $field = $this->taskFields()['due_date'];

        if (! $field instanceof CustomField) {
            return null;
        }

        $due = $task->customFieldValues->firstWhere('custom_field_id', $field->getKey())?->getValue();

        return $due instanceof DateTimeInterface ? CarbonImmutable::instance($due) : null;
    }

    /**
     * @return array{status: ?CustomField, due_date: ?CustomField}
     */
    private function taskFields(): array
    {
        return once(function (): array {
            $fields = CustomField::query()
                ->forEntity(Task::class)
                ->with('options')
                ->whereIn('code', [TaskCustomField::STATUS->value, TaskCustomField::DUE_DATE->value])
                ->get();

            return [
                'status' => $fields->firstWhere('code', TaskCustomField::STATUS->value),
                'due_date' => $fields->firstWhere('code', TaskCustomField::DUE_DATE->value),
            ];
        });
    }

    private function operatorField(): ?CustomField
    {
        return once(fn (): ?CustomField => CustomField::query()
            ->forEntity(Opportunity::class)
            ->where('code', OpportunityCustomField::OPERATOR->value)
            ->first());
    }

    /**
     * Move card to new position using DecimalPosition service.
     *
     * Overrides the default Flowforge implementation to properly handle
     * custom field columns which are stored in a polymorphic table.
     *
     * @throws Throwable
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        // The platform owns a collaboration's rung, so a drop on the mirror never lands.
        if ($this->isKocLadder()) {
            return;
        }

        $board = $this->getBoard();
        $query = $board->getQuery();

        throw_unless($query instanceof Builder, InvalidArgumentException::class, 'Board query not available');

        $card = (clone $query)->find($cardId);
        throw_unless($card, InvalidArgumentException::class, "Card not found: {$cardId}");

        abort_unless(Gate::allows('update', $card), 403);
        abort_unless($this->ownsColumn($targetColumnId), 422);

        $newPosition = $this->calculatePositionBetweenCards($afterCardId, $beforeCardId, $targetColumnId);

        DB::transaction(function () use ($card, $board, $targetColumnId, $newPosition): void {
            $columnIdentifier = $board->getColumnIdentifierAttribute();
            $columnValue = $this->resolveStatusValue($card, $columnIdentifier, $targetColumnId);
            $positionIdentifier = $board->getPositionIdentifierAttribute();

            $card->update([$positionIdentifier => $newPosition]);

            /** @var Opportunity $card */
            $card->saveCustomFieldValue($this->cardColumnField(), $columnValue);
        });

        $this->dispatch('kanban-card-moved', [
            'cardId' => $cardId,
            'columnId' => $targetColumnId,
            'position' => $newPosition,
        ]);
    }

    private function quickCreateForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->placeholder(__('filament/pages/boards.opportunities.form.name_placeholder'))
                    ->columnSpanFull(),
                RecordSelect::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                RecordSelect::make('contact_id')
                    ->label(__('filament/resources/opportunity.fields.contact_id.label'))
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),
                CustomFields::form()
                    ->except([(string) $this->cardColumnField()->code])
                    ->build()
                    ->columnSpanFull()
                    ->columns(1),
            ])
            ->columns(2);
    }

    /**
     * Get columns for the board.
     *
     * @return array<Column>
     *
     * @throws Exception
     */
    private function getColumns(): array
    {
        return $this->ladderOptions()->map(fn (array $option): Column => Column::make((string) $option['id'])
            ->color($option['color'])
            ->label($option['name'])
        )->toArray();
    }

    /**
     * "Today" and "tomorrow" are questions about the viewer's calendar, so the boundary
     * moves into their zone, but the close date itself must not. Unlike the tasks
     * board's due date, this is a plain calendar date the package stores at midnight
     * UTC: converting it into a negative-offset zone walks it back past midnight and
     * the card reads a day early. Move only the "today" it is measured against, and
     * compare the two as dates rather than instants. Midnight UTC and midnight in
     * Los Angeles are the same calendar day but seven hours apart, so an instant
     * comparison would call a date closing today overdue.
     */
    private function formatCloseDateBadge(?string $state): string
    {
        if (blank($state)) {
            return '';
        }

        $date = Date::parse($state);
        $viewerToday = Date::now(FilamentTimezone::get())->startOfDay();

        $closesOn = $date->toDateString();
        $today = $viewerToday->toDateString();
        $tomorrow = $viewerToday->copy()->addDay()->toDateString();

        return match (true) {
            $closesOn < $today => $date->format('M j').' (Overdue)',
            $closesOn === $today => 'Closes Today',
            $closesOn === $tomorrow => 'Closes Tomorrow',
            default => $date->format('M j'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCard(array $data, CreateAction $action): Opportunity
    {
        $columnId = $action->getArguments()['column'] ?? null;

        abort_unless(blank($columnId) || $this->ownsColumn((string) $columnId), 422);

        /** @var Workspace $currentWorkspace */
        $currentWorkspace = Auth::guard('web')->user()->currentWorkspace;

        /** @var Opportunity $opportunity */
        $opportunity = $currentWorkspace->opportunities()->create($data);

        if (filled($columnId)) {
            $opportunity->saveCustomFieldValue($this->cardColumnField(), $columnId);
        }

        // The platform owns the rung, so a ladder card is never given a position.
        if (filled($columnId) && ! $this->isKocLadder()) {
            $opportunity->order_column = (float) $this->getBoardPositionInColumn($columnId);
            $opportunity->saveQuietly();
        }

        return $opportunity;
    }

    private function ownsColumn(string $id): bool
    {
        return $this->ladderOptions()->contains(fn (array $option): bool => (string) $option['id'] === $id);
    }

    private function isKocLadder(): bool
    {
        return $this->columnField()?->code === OpportunityCustomField::KOC_STAGE->value;
    }

    private function columnField(): ?CustomField
    {
        return $this->columnField ??= self::resolveColumnField();
    }

    private function cardColumnField(): CustomField
    {
        $field = $this->columnField();

        throw_unless($field instanceof CustomField, InvalidArgumentException::class, 'Board column field not available');

        return $field;
    }

    /**
     * The collaboration ladder owns the board where the workspace declares it.
     * A brand tenant declares only the sales pipeline, so that stays its board.
     */
    private static function resolveColumnField(): ?CustomField
    {
        foreach ([OpportunityCustomField::KOC_STAGE, OpportunityCustomField::STAGE] as $code) {
            $field = CustomField::query()
                ->forEntity(Opportunity::class)
                ->where('code', $code)
                ->first();

            if ($field instanceof CustomField) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    private function ladderOptions(): Collection
    {
        $field = $this->columnField();

        if (! $field instanceof CustomField) {
            return collect();
        }

        $colorsEnabled = $field->settings->enable_option_colors ?? false;

        return $field->options->map(fn (CustomFieldOption $option): array => [
            'id' => $option->getKey(),
            'custom_field_id' => $option->getAttribute('custom_field_id'),
            'name' => $option->getAttribute('name'),
            'color' => $colorsEnabled ? ($option->settings->color ?? 'gray') : 'gray',
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return self::resolveColumnField() instanceof CustomField;
    }
}
