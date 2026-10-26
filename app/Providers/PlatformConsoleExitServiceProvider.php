<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Platform\ConsoleExit;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;

/**
 * Puts the `← Console` exit in the app panel's chrome (fork addition — see
 * NOTICE; card t_619a7e0f).
 *
 * WHY A SEPARATE PROVIDER, and not `AppPanelProvider::panel()`. A render hook is
 * panel configuration, so it belongs there — but that method is on the fork's
 * shrink-only length budget (`phpstan-method-length.php`), and this control is
 * not worth spending it on. Registering the hook from its own provider keeps the
 * panel provider's diff at zero and leaves the existing hooks untouched.
 *
 * The hook is registered GLOBALLY because that is the only registration
 * Filament offers, so the closure narrows to the `app` panel itself — the
 * sysadmin panel has no console to return to. `USER_MENU_BEFORE` renders inside
 * the topbar's trailing cluster, which every authenticated panel page shares, so
 * the control is present on all of them rather than only the dashboard.
 */
final class PlatformConsoleExitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): View|Factory|string => $this->consoleExit(),
        );
    }

    /**
     * The control, or an empty string when this deployment/page must not carry
     * one: no console declared (a stock self-host install) or the sysadmin panel.
     */
    private function consoleExit(): View|Factory|string
    {
        if (Filament::getCurrentPanel()?->getId() !== 'app') {
            return '';
        }

        $href = ConsoleExit::href(request());

        if ($href === null) {
            return '';
        }

        return view('filament.app.console-exit', [
            'href' => $href,
            'title' => ConsoleExit::title(),
        ]);
    }
}
