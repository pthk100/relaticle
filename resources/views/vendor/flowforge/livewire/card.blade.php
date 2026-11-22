@props(['columnId', 'record'])

{{-- Published copy of flowforge::livewire.card: the package marks every card
     x-sortable-item, and a board with no position identifier must mark none.

     It also decides what a card leads with. A board whose record title is a
     search string (the KOC ladder's `{Brand} | {SKU} - @{handle}`) renders the
     two facts that string is made of, read off the record, and keeps the string
     on the card's title attribute where hover and copy can still reach it. A
     board whose title is already a label (the sales board, the tasks board)
     renders exactly what the package ships. --}}

@php
    $processedRecordActions = $this->getBoard()->getBoardRecordActions($record);
    $hasActions = !empty($processedRecordActions);
    $cardAction = $this->getBoard()->getCardAction();
    $hasCardAction = $cardAction !== null;
    $hasPositionIdentifier = $this->getBoard()->getPositionIdentifierAttribute() !== null;

    // A card action configured with a url must navigate like a native link instead of
    // mounting a modal, so middle-click, cmd-click and "copy link" keep working.
    //
    // The rule is Filament's own, taken from ListRecords::table(): a url on the action
    // decides. There, recordAction() skips any action whose getUrl() is filled and
    // recordUrl() takes it instead, with no check of the action's modal state. An
    // action carrying both a url and a confirmation therefore renders as a plain link
    // in core too. Mirroring that keeps a board card and a table row behaving the same
    // way for the same action, which matters more than second-guessing the config.
    //
    // Only POST urls are excluded: those need a form rather than an anchor.
    $cardActionInstance = $this->getBoard()->resolveCardAction($processedRecordActions);
    $cardActionUrl = $cardActionInstance?->shouldPostToUrl()
        ? null
        : $cardActionInstance?->getUrl();
    $hasCardActionUrl = filled($cardActionUrl);
    $cardActionHref = $hasCardActionUrl
        ? \Filament\Support\generate_href_html(
            $cardActionUrl,
            $cardActionInstance->shouldOpenUrlInNewTab(),
            hasNestedClickEventHandler: true,
        )
        : null;

    // The board decides whether its title is a search string; null means "use the
    // title as the card's content", which is every board except the ladder. The
    // derivation lives on the board, not on the Livewire page, so nothing here
    // widens the component's browser-callable surface.
    $identity = $record['model'] instanceof \App\Models\Opportunity
        ? $this->getBoard()?->cardIdentity($record['model'])
        : null;
@endphp

<div
    @class([
        'flowforge-card mb-3 bg-white dark:bg-gray-900 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden transition-all duration-200 hover:shadow-md',
        'cursor-pointer' => $hasActions || $hasCardAction,
        'cursor-pointer transition-all duration-100 ease-in-out hover:shadow-lg hover:border-gray-400 active:shadow-md' => $hasCardAction,
        'cursor-grab hover:cursor-grabbing' => $hasPositionIdentifier,
        'cursor-default' => !$hasActions && !$hasCardAction && !$hasPositionIdentifier,
    ])
    @if($hasPositionIdentifier)
        x-sortable-item="{{ $record['id'] }}"
    @endif
    data-card-id="{{ $record['id'] }}"
    @if($identity !== null)
        title="{{ $record['title'] }}"
    @endif
    @if($hasPositionIdentifier)
        x-sortable-handle
    @endif
    data-position="{{ $record['position'] ?? '' }}"
>
    <div class="flowforge-card-content">
        <div class="flex items-start justify-between mb-2">
            @if($identity === null)
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white p-3"
                    @if($hasCardAction && $cardAction && !$hasCardActionUrl)
                        wire:click="mountAction('{{ $cardAction }}', [], @js(['recordKey' => $record['id']]))"
                    style="cursor: pointer;"
                    @endif
                >
                    @if($hasCardActionUrl)
                        <a {{ $cardActionHref }} class="block">
                            {{ $record['title'] }}
                        </a>
                    @else
                        {{ $record['title'] }}
                    @endif
                </h4>
            @else
                {{-- Two lines, never three: the creator is what an operator scans for,
                     and the product line is one ellipsised row however long the SKU. --}}
                <div class="min-w-0 flex-1 p-3"
                     @if($hasCardAction && $cardAction && !$hasCardActionUrl)
                         wire:click="mountAction('{{ $cardAction }}', [], @js(['recordKey' => $record['id']]))"
                     style="cursor: pointer;"
                    @endif
                >
                    <h4 data-card-handle class="truncate text-sm font-semibold text-gray-900 dark:text-white">@if($hasCardActionUrl)<a {{ $cardActionHref }} class="block truncate">{{ $identity['handle'] ?? '—' }}</a>@else{{ $identity['handle'] ?? '—' }}@endif</h4>
                    @if(filled($identity['product'] ?? null))
                        <p data-card-product class="mt-0.5 truncate whitespace-nowrap text-[0.8125rem] text-gray-500 dark:text-gray-400">{{ $identity['product'] }}</p>
                    @endif
                </div>
            @endif

            @if($hasActions)
                <div class="m-3">
                    <x-filament-actions::group :actions="$processedRecordActions"/>
                </div>
            @endif
        </div>

        <div class="px-3 pb-3"
             @if($hasCardAction && $cardAction && !$hasCardActionUrl)
                 wire:click="mountAction('{{ $cardAction }}', [], @js(['recordKey' => $record['id']]))"
             style="cursor: pointer;"
            @endif
        >
            {{-- Render card schema with compact spacing --}}
            @if(filled($record['schema']))
                @if($hasCardActionUrl)
                    <a {{ $cardActionHref }} class="block">
                        {{ $record['schema'] }}
                    </a>
                @else
                    {{ $record['schema'] }}
                @endif
            @endif
        </div>
    </div>
</div>
