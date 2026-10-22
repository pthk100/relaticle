<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Platform\PlatformConsole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the CRM's own login surface when this deployment has a platform console.
 *
 * Fork addition (see NOTICE; card t_fc36fa36).
 * `docs/specs/module-session-lifecycle.contract.md` §8 item 3: a tenant must
 * never be served Relaticle's email+password form — the console is the only door,
 * and it hands the person back with `/console/?realm=<slug>&returnTo=<module>`.
 *
 * WHY MIDDLEWARE AND NOT `mount()` ON THE PAGES: the acceptance is a **302**. A
 * Livewire page redirect (`$this->redirect()`) answers the initial page load with
 * `200` plus a redirect effect that only the browser's JS follows, and Filament's
 * own upstream `mount()` reaches for `redirect()->intended()` from inside a
 * component where nothing is returned. Neither can satisfy "302 to /console/",
 * and both depend on whether the visitor is already signed in. This middleware
 * runs before the component, so the answer is the same 302 either way.
 *
 * MATCHED BY ROUTE NAME SUFFIX, not by panel id: a stock fork install that names
 * its panel differently still gets the closure, and the redirect applies to the
 * auth pages alone — never to the break-glass broker (`/auth/broker/{token}`),
 * the provisioning API, or a signed-in tenant's `password.confirm` flows.
 */
final class RedirectPlatformAuthPagesToConsole
{
    /** The panel auth pages that exist solely to take a password. */
    private const array AUTH_PAGE_ROUTE_SUFFIXES = [
        '.auth.login',
        '.auth.password-reset.request',
        '.auth.password-reset.reset',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $console = PlatformConsole::url();

        if ($console === null) {
            return $next($request);
        }

        if ($this->isAuthPage($request)) {
            return redirect()->to($console);
        }

        return $next($request);
    }

    private function isAuthPage(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (! is_string($name)) {
            return false;
        }

        foreach (self::AUTH_PAGE_ROUTE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
