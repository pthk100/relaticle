<x-filament-panels::page>
    <div class="h-[calc(100vh-11rem)] relative">
        @if ($this->showsEmptyLadder())
            <div class="flex h-full flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 p-6 text-center dark:border-gray-700">
                <x-filament::icon
                    icon="heroicon-o-users"
                    class="mb-2 h-10 w-10 text-gray-400 dark:text-gray-600"
                />

                <p class="text-sm font-medium text-gray-900 dark:text-white">
                    {{ __('filament/pages/boards.opportunities.empty.title') }}
                </p>

                <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                    {{ __('filament/pages/boards.opportunities.empty.body') }}
                </p>
            </div>
        @else
            {{ $this->board }}
        @endif
    </div>
</x-filament-panels::page>
