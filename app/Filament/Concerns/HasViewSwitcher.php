<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Resources\NoteResource\Pages\NotesCards;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\Flowforge\BoardResourcePage;

/**
 * Puts the view switcher at the start of the row under the topbar, so it keeps
 * a stable position when toggling between a resource's layouts.
 */
trait HasViewSwitcher
{
    public function mountHasViewSwitcher(): void
    {
        static::getResource()::rememberViewMode(static::getResourcePageName());
    }

    public function getHeader(): ?View
    {
        $header = parent::getHeader();

        if (! $this instanceof BoardResourcePage || ! $header instanceof View) {
            return $header;
        }

        return view('filament.app.board-header', [
            'boardToolbar' => $header,
            'heading' => $this->getHeading(),
            'viewSwitcher' => $this->getViewSwitcher(),
            'boardName' => $this->getBoardName(),
            'boardCaption' => $this->getBoardCaption(),
        ]);
    }

    /**
     * The board names itself under the topbar title. The topbar shows the page
     * title ("Opportunities"), which is the same word on the list and on the
     * board, so this line is the only text on the surface that says which of the
     * two views a reader is looking at. A board with its own vocabulary overrides
     * this.
     */
    public function getBoardName(): string
    {
        $heading = $this->getHeading();

        return __('filament/pages/boards.board_name_default', [
            'resource' => $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : (string) $heading,
        ]);
    }

    /**
     * A board that has something to explain about its own query says it here, on
     * the line under its name. The page's Filament subheading is not used: the
     * board toolbar renders that inside the row it shares with the view switcher
     * and the search field, which is where a caption wrapped to two lines and ran
     * into the search field.
     */
    public function getBoardCaption(): ?string
    {
        return null;
    }

    public function getViewSwitcher(): ?Htmlable
    {
        $resource = static::getResource();
        $views = [];

        foreach ($resource::getPages() as $name => $registration) {
            $page = $registration->getPage();

            $isCollectionPage = is_a($page, ListRecords::class, true) || is_a($page, BoardResourcePage::class, true);

            if (! $isCollectionPage || ! $page::canAccess()) {
                continue;
            }

            $view = match (true) {
                is_a($page, BoardResourcePage::class, true) => 'board',
                $page === NotesCards::class => 'cards',
                default => 'list',
            };

            $views[$view] = [
                'url' => $resource::getUrl($name),
                'active' => $this instanceof $page,
            ];
        }

        if (count($views) < 2) {
            return null;
        }

        return new HtmlString(view('filament.app.view-switcher', ['views' => $views])->render());
    }
}
