<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Models\CustomField;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Facades\CustomFields;

trait HasCustomFieldColumns
{
    public function table(Table $table): Table
    {
        $builder = CustomFields::table()->forModel($this->getModel());

        $fields = $builder->getFields()->keyBy(fn (CustomField $field): string => $field->getFieldName());

        $columns = $builder->columns()
            ->map(function (Column $column) use ($fields): Column {
                $field = $fields->get($column->getName());

                if (! $field instanceof CustomField) {
                    return $column;
                }

                $column->label(ColumnHeaderLabel::make($field->name, $field->typeData->icon));

                return self::formatStoredOptionName($column, $field);
            })
            ->all();

        $titleColumn = CrmEntity::tryFromModel(new ($this->getModel()))?->titleColumn();

        foreach ($table->getColumns() as $column) {
            $label = $column->getLabel();

            if ($column->getName() === $titleColumn) {
                continue;
            }

            if ($label instanceof Htmlable) {
                continue;
            }

            $column->label(ColumnHeaderLabel::make($label, self::nativeColumnIcon($column->getName())));
        }

        return $table
            ->modifyQueryUsing(function (Builder $query): void {
                $query->with('customFieldValues.customField');
            })
            ->deferFilters(false)
            ->pushColumns($columns)
            ->pushFilters(self::withoutDuplicatedLadderFilter($builder->filters()));
    }

    /**
     * The plugin generates a filter for every filterable field, and the ladder
     * declares `koc_stage` filterable so its options exist. `KocPipelineFilters`
     * already owns that field, and the accepted-only toggle beside it depends on
     * that filter's shape, so the generated duplicate is dropped, exactly as the
     * opportunity form excludes the field it does not edit. Two filters for one
     * field also means two labels, and the generated one would print the machine
     * vocabulary.
     *
     * @param  Collection<int, BaseFilter>  $filters
     * @return array<int, BaseFilter>
     */
    private static function withoutDuplicatedLadderFilter(Collection $filters): array
    {
        return $filters
            ->reject(fn (BaseFilter $filter): bool => $filter->getName() === 'custom_fields.'.OpportunityField::KOC_STAGE->value)
            ->values()
            ->all();
    }

    /**
     * `koc_stage` stores the platform's machine vocabulary as the option row's
     * NAME, so the resolved state is `sample_pending` rather than words. Every
     * other choice field already stores the words it prints.
     */
    private static function formatStoredOptionName(Column $column, CustomField $field): Column
    {
        if ($field->code !== OpportunityField::KOC_STAGE->value || ! $column instanceof TextColumn) {
            return $column;
        }

        return $column->formatStateUsing(
            fn (?string $state): ?string => $state === null ? null : OpportunityField::kocStageLabel($state),
        );
    }

    private static function nativeColumnIcon(string $name): Heroicon
    {
        return match ($name) {
            'accountOwner.name' => Heroicon::OutlinedUser,
            'creator.name' => Heroicon::OutlinedUserCircle,
            'assignees.name' => Heroicon::OutlinedUsers,
            'company.name' => Heroicon::OutlinedBuildingOffice2,
            'relations' => Heroicon::OutlinedLink,
            'created_at' => Heroicon::OutlinedCalendar,
            'updated_at' => Heroicon::OutlinedClock,
            'deleted_at' => Heroicon::OutlinedTrash,
            default => Heroicon::OutlinedBars3BottomLeft,
        };
    }
}
