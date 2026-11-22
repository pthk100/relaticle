<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Filters;

use App\Enums\CustomFields\OpportunityField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use Closure;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The KOC ladder's filter list, shared by the list and the board.
 *
 * The platform projects a candidate at activation, so the operations workspace
 * holds one opportunity per pitch, including the ones that never reply. The
 * pipeline views answer that by showing accepted collaborations only, and hand
 * the pitches back through one toggle.
 *
 * The ladder's fields describe a collaboration we run, so a workspace that
 * declares no `koc_stage` field gets no filters: its opportunities are its own
 * sales deals, and a filter it cannot use is noise in its chrome.
 */
final class KocPipelineFilters
{
    /**
     * @param  bool  $acceptedOnlyByDefault  The board is the pipeline view the
     *                                       activation ruling names, so it opens
     *                                       on collaborations only. The list keeps
     *                                       the same filters but starts unfiltered:
     *                                       a user who never opens a KOC view must
     *                                       not lose hand-created sales records.
     * @return list<BaseFilter>
     */
    public static function all(bool $acceptedOnlyByDefault = false): array
    {
        $field = CustomField::query()
            ->forEntity(Opportunity::class)
            ->where('code', OpportunityField::KOC_STAGE)
            ->first();

        if (! $field instanceof CustomField) {
            return [];
        }

        return [self::stage($field), self::acceptedOnly($field, $acceptedOnlyByDefault)];
    }

    private static function stage(CustomField $field): SelectFilter
    {
        return SelectFilter::make(OpportunityField::KOC_STAGE->value)
            ->label(__('filament/resources/opportunity.filters.koc_stage'))
            ->options(fn (): array => $field->options
                ->mapWithKeys(fn (CustomFieldOption $option): array => [
                    (string) $option->getKey() => OpportunityField::kocStageLabel((string) $option->getAttribute('name')),
                ])
                ->all())
            ->query(function (Builder $query, array $data) use ($field): Builder {
                $optionId = $data['value'] ?? null;

                if (! is_string($optionId) || $optionId === '') {
                    return $query;
                }

                return $query->whereHas('customFieldValues', self::holdingOption($field, $optionId));
            });
    }

    private static function acceptedOnly(CustomField $field, bool $onByDefault): Filter
    {
        return Filter::make('accepted_only')
            ->label(__('filament/resources/opportunity.filters.accepted_only'))
            ->toggle()
            ->default($onByDefault)
            ->query(function (Builder $query, array $data) use ($field, $onByDefault): Builder {
                if (! ($data['isActive'] ?? $onByDefault)) {
                    return $query;
                }

                $query->whereHas('customFieldValues', function (Builder $values) use ($field): void {
                    /** @var Builder<CustomFieldValue> $values */
                    $values->holdingAValue($field);
                });

                $pitch = $field->options->firstWhere('name', OpportunityField::KOC_STAGE_PITCH);

                if (! $pitch instanceof CustomFieldOption) {
                    return $query;
                }

                return $query->whereDoesntHave('customFieldValues', self::holdingOption($field, (string) $pitch->getKey()));
            });
    }

    /**
     * @return Closure(Builder<Model>): void
     */
    private static function holdingOption(CustomField $field, string $optionId): Closure
    {
        return static function (Builder $values) use ($field, $optionId): void {
            /** @var Builder<CustomFieldValue> $values */
            $values->holdingOption($field, $optionId);
        };
    }
}
