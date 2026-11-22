@php use Filament\Support\Facades\FilamentAsset; @endphp
@props(['columns', 'config'])

{{-- Published copy of flowforge::index: the lane row is the board's only
     horizontal scrollport, and a board wider than the window has to say so. The
     native scrollbar cannot carry that message: macOS paints overlay scrollbars
     that take no room and draw nothing until the row is moved, so a lane past
     the right edge has no sign it exists and a card dragged onto it looks
     deleted. The row therefore pages itself with a control that only exists
     while there is somewhere to go. --}}

<div
    class="w-full h-full flex flex-col relative"
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('flowforge', package: 'relaticle/flowforge') }}"
    x-data="flowforge({
        state: {
            columns: @js($columns),
            titleField: '{{ $config['recordTitleAttribute'] }}',
            columnField: '{{ $config['columnIdentifierAttribute'] }}',
            cardLabel: @js($config['cardLabel']),
            pluralCardLabel: @js($config['pluralCardLabel']),
        }
    })"
>

    @unless($config['headerToolbar'] ?? false)
        @include('flowforge::components.filters')
    @endunless

    <!-- Board Content -->
    <div
        class="flex-1 overflow-hidden h-full relative"
        x-data="{
            canPageBack: false,
            canPageForward: false,
            refreshLaneRow() {
                const row = this.$refs.laneRow;

                if (!row) {
                    return;
                }

                this.canPageBack = row.scrollLeft > 1;
                this.canPageForward = row.scrollLeft + row.clientWidth < row.scrollWidth - 1;
            },
            pageLaneRow(direction) {
                const row = this.$refs.laneRow;

                if (!row) {
                    return;
                }

                // One lane at a time, so a page never skips a lane the reader has
                // not seen yet. 320 is a 300px lane plus the gap it sits in.
                row.scrollBy({ left: direction * 320 });
                this.refreshLaneRow();
            },
        }"
        x-init="$nextTick(() => refreshLaneRow())"
        x-on:morph.updated.window="refreshLaneRow()"
        x-on:resize.window.debounce.150ms="refreshLaneRow()"
    >
        <div
            x-ref="laneRow"
            x-on:scroll.throttle.100ms="refreshLaneRow()"
            class="flowforge-lane-row flex flex-row h-full overflow-x-auto overflow-y-hidden gap-x-5"
        >
            @foreach($columns as $columnId => $column)
                <x-flowforge::column
                    :columnId="$columnId"
                    :column="$column"
                    :config="$config"
                    wire:key="column-{{ $columnId }}"
                />
            @endforeach
        </div>

        <template x-if="canPageBack">
            <button
                type="button"
                data-lane-row-back
                x-on:click="pageLaneRow(-1)"
                aria-label="{{ __('boards.previous_lanes') }}"
                class="absolute top-1/2 start-2 z-10 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400 dark:hover:text-gray-200"
            >
                <x-filament::icon icon="heroicon-o-chevron-left" class="size-5" />
            </button>
        </template>

        <template x-if="canPageForward">
            <button
                type="button"
                data-lane-row-next
                x-on:click="pageLaneRow(1)"
                aria-label="{{ __('boards.next_lanes') }}"
                class="absolute top-1/2 end-2 z-10 grid size-8 -translate-y-1/2 place-items-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400 dark:hover:text-gray-200"
            >
                <x-filament::icon icon="heroicon-o-chevron-right" class="size-5" />
            </button>
        </template>
    </div>

    <x-filament-actions::modals/>
</div>
