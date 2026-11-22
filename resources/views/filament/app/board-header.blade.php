{{-- The board's header row.

     Order matters: the heading teleports to the topbar, the view switcher keeps the
     start of the row so it does not jump between the list and the board, the board's
     own name and caption fill the line beside it, and the toolbar (search, filters,
     actions) closes the row.

     The identity block is its own flex item rather than part of the toolbar: the
     toolbar renders the page's subheading inside the row it shares with the search
     field, and a two-line caption there ran into the search field. --}}
<div class="fi-board-header">
    @include('filament.app.topbar-page-heading', ['heading' => $heading])

    {{ $viewSwitcher }}

    <div class="fi-board-identity">
        <h2 class="fi-board-name">{{ $boardName }}</h2>

        @if (filled($boardCaption))
            <p class="fi-board-caption" title="{{ $boardCaption }}">{{ $boardCaption }}</p>
        @endif
    </div>

    {!! $boardToolbar !!}
</div>
