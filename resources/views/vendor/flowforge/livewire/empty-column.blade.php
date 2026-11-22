@props(['columnId', 'pluralCardLabel'])

{{-- Published copy of flowforge::livewire.empty-column: a column whose own filter
     withholds its rows has to say so, or it claims there is no work at all. --}}

@php
    $hiddenCards = $this instanceof \App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard
        ? $this->hiddenCardsInColumn((string) $columnId)
        : 0;
@endphp

<div class="p-3 flex flex-col items-center justify-center h-full min-h-[150px] rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-700">
    <x-filament::icon 
        icon="heroicon-o-archive-box" 
        class="w-10 h-10 text-gray-400 dark:text-gray-600 mb-2" 
    />
    <p class="text-sm text-gray-500 dark:text-gray-400">
        @if ($hiddenCards > 0)
            {{ trans_choice('filament/pages/boards.opportunities.empty.hidden', $hiddenCards, ['count' => $hiddenCards]) }}
        @else
            {{ __('flowforge::flowforge.no_cards_in_column', ['cardLabel' => strtolower($pluralCardLabel)]) }}
        @endif
    </p>
</div>
