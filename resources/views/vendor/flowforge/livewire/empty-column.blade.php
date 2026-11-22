@props(['columnId', 'pluralCardLabel'])

{{-- Published copy of flowforge::livewire.empty-column.

     Two things the package's copy got wrong on this board:

       - a column whose own filter withholds its rows has to say so, or it claims
         there is no work at all;
       - a column says what fills it, in its own words, instead of the same
         sentence in every lane.

     No glyph. The package drew a lidded archive box here, which beside a pipeline
     lane reads as "archive this lane", and heroicons v2 ships no neutral circle to
     stand in for it (`o-circle` does not exist; every circle it has carries a verb:
     minus is remove, x is cancel). The lane's own sentence is the sign. --}}

@php
    $isBoard = $this instanceof \App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;

    $hiddenCards = $isBoard ? $this->hiddenCardsInColumn((string) $columnId) : 0;
    $laneHint = $isBoard ? $this->emptyLaneHint((string) $columnId) : null;
@endphp

<div class="flowforge-empty-column p-3 flex flex-col items-center justify-center h-full min-h-[150px] rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700">
    <p class="text-balance text-center text-sm text-gray-500 dark:text-gray-400">
        @if ($hiddenCards > 0)
            {{ trans_choice('filament/pages/boards.opportunities.empty.hidden', $hiddenCards, ['count' => $hiddenCards]) }}
        @elseif (filled($laneHint))
            {{ $laneHint }}
        @else
            {{ __('flowforge::flowforge.no_cards_in_column', ['cardLabel' => strtolower($pluralCardLabel)]) }}
        @endif
    </p>
</div>
