<?php

declare(strict_types=1);

namespace App\Support\Platform;

/**
 * Where a tenant signs in.
 *
 * Fork addition (see NOTICE; card t_fc36fa36). On this deployment the CRM never
 * serves its own tenant login: the platform console is the only door, and it
 * hands a signed-in person back with `/console/?realm=<slug>&returnTo=<module>`.
 * A tenant that met Relaticle's email+password form instead would be a second,
 * unmanaged identity surface — `docs/specs/module-session-lifecycle.contract.md`
 * §8 item 3 forbids it for this deployment.
 *
 * ENV-GATED ON PURPOSE (`PLATFORM_CONSOLE_URL`). Upstream's self-host story is
 * "Relaticle is your app, its login page is yours"; a fork that deleted that page
 * outright could not be rebased cheaply and would break every stock install.
 * Unset ⇒ null ⇒ upstream behaviour untouched. Configured ⇒ the auth pages are
 * closed and every entry is a 302 to the console.
 */
final class PlatformConsole
{
    /** The console front door, or null when this deployment does not declare one. */
    public static function url(): ?string
    {
        $configured = config('relaticle.platform.console_url');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    public static function isConfigured(): bool
    {
        return self::url() !== null;
    }
}
