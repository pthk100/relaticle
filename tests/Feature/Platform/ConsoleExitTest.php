<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Support\Platform\ConsoleExit;
use App\Support\Platform\KeycloakEndSession;
use App\Support\Platform\TenantSurface;
use Illuminate\Http\Request;

/**
 * The CRM's `← Console` exit (fork addition — see NOTICE; card t_619a7e0f).
 *
 * `design/CONTRACT.md` §11 requirement 7 makes the exit mandatory for every
 * module, and the two module SPAs already ship one built to ONE shape:
 *
 *   /console/?realm=<slug>&returnTo=<url-encoded path>
 *
 * These tests pin that shape for the CRM, and each of the four properties that
 * can fail independently:
 *
 * 1. The realm is the SESSION's, read server-side from the records the federation
 *    writes — never a constant, and never anything the request supplied.
 * 2. `returnTo` is the path the person is on, `/crm` prefix included (nginx hands
 *    PHP the unrewritten REQUEST_URI).
 * 3. With no realm the URL is still a console URL a person can sign in from — the
 *    documented fallback — and carries no empty `realm=`.
 * 4. With no console declared the deployment is a stock self-host install and the
 *    chrome must draw nothing at all.
 */
beforeEach(function (): void {
    TenantSurface::forget();

    config()->set('app.url', 'https://console-crm-uat.xgate.com/crm');
    config()->set('relaticle.platform.console_url', 'https://console-crm-uat.xgate.com/console/');
});

/** A user whose identity arrived through the platform's federated hand-off. */
function federatedConsoleUser(Workspace $workspace, string $sub = 'sub-1'): User
{
    $user = User::factory()->create();
    $user->socialAccounts()->create([
        'provider_name' => TenantSurface::PROVIDER,
        'provider_id' => 'tenant-'.$workspace->slug.'|'.$sub,
    ]);
    $workspace->users()->attach($user->getKey(), ['role' => 'admin']);
    $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    return $user->fresh();
}

/** The request a panel page arrives on: browser URI, prefix preserved. */
function consoleExitRequest(string $uri = '/crm/app/leads'): Request
{
    return Request::create('https://console-crm-uat.xgate.com'.$uri, 'GET');
}

it('builds the same URL shape as the two module builders', function (): void {
    $this->actingAs(federatedConsoleUser(Workspace::factory()->create(['slug' => 'solstice'])));
    TenantSurface::forget();

    $request = consoleExitRequest();
    $request->setLaravelSession(app('session.store'));
    $request->session()->put(KeycloakEndSession::SESSION_KEY, ['realm' => 'tenant-solstice', 'id_token' => 'id-tok']);

    expect(ConsoleExit::href($request))->toBe(
        'https://console-crm-uat.xgate.com/console/?realm=solstice&returnTo=%2Fcrm%2Fapp%2Fleads',
    );
});

it('drops the tenant- prefix and keeps a deep link, query included, in returnTo', function (): void {
    $this->actingAs(federatedConsoleUser(Workspace::factory()->create(['slug' => 'solstice'])));
    TenantSurface::forget();

    $request = consoleExitRequest('/crm/app/solstice/companies?tableSort=name');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put(KeycloakEndSession::SESSION_KEY, ['realm' => 'tenant-solstice']);

    $href = (string) ConsoleExit::href($request);

    expect($href)->toStartWith('https://console-crm-uat.xgate.com/console/?realm=solstice&returnTo=')
        // The prefix the edge serves, URL-encoded exactly as `encodeURIComponent`
        // would: `/` → `%2F`, and the query's `=`/`?` escaped with it.
        ->and($href)->toContain('%2Fcrm%2Fapp%2Fsolstice%2Fcompanies')
        ->and(urldecode(substr($href, strpos($href, 'returnTo=') + 9)))
        ->toBe('/crm/app/solstice/companies?tableSort=name');
});

it('learns the realm from the federated identity link when the session has no retained one', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'solstice']);
    // A session established before the callback retained the id_token: the
    // identity link is the only record naming the realm.
    $this->actingAs(federatedConsoleUser($workspace));

    $request = consoleExitRequest();
    $request->setLaravelSession(app('session.store'));

    expect(ConsoleExit::realmSlug($request))->toBe('solstice')
        ->and(ConsoleExit::href($request))->toContain('realm=solstice');
});

it('falls back to a console URL a person can sign in from when the realm is unknown', function (): void {
    // A LOCAL user (upstream's own self-host onboarding): no federation link, so
    // no realm is known — and the console's front door asks which workspace.
    $this->actingAs(User::factory()->create());

    $request = consoleExitRequest('/crm/app');
    $request->setLaravelSession(app('session.store'));

    $href = (string) ConsoleExit::href($request);

    expect($href)->toBe('https://console-crm-uat.xgate.com/console/?returnTo=%2Fcrm%2Fapp')
        // Never an empty realm=: a blank hint is not the same as no hint, and the
        // console would read it as a workspace named "".
        ->and($href)->not->toContain('realm=');
});

it('drops a realm that is not a single path segment instead of emitting it', function (string $realm): void {
    $this->actingAs(User::factory()->create());

    $request = consoleExitRequest();
    $request->setLaravelSession(app('session.store'));
    $request->session()->put(KeycloakEndSession::SESSION_KEY, ['realm' => $realm]);

    expect(ConsoleExit::realmSlug($request))->toBeNull()
        ->and((string) ConsoleExit::href($request))->not->toContain('realm=');
})->with([
    'a traversal' => ['tenant-../other'],
    'a second segment' => ['tenant-solstice/admin'],
    'an empty realm' => ['tenant-'],
    'an injection' => ['tenant-solstice&realm=other'],
    'uppercase' => ['tenant-Solstice'],
]);

it('degrades a non-absolute request URI to the console root rather than an unservable returnTo', function (): void {
    $this->actingAs(User::factory()->create());

    $request = Request::create('https://console-crm-uat.xgate.com/', 'GET');
    $request->setLaravelSession(app('session.store'));

    // `getRequestUri()` returns what the SAPI reported; a value that is not
    // root-absolute is not this module's location.
    $request->server->set('REQUEST_URI', 'crm/app');

    expect(ConsoleExit::returnTo($request))->toBe('/');
});

it('draws no control at all when the deployment declares no console', function (): void {
    config()->set('relaticle.platform.console_url', null);

    $this->actingAs(User::factory()->create());

    $request = consoleExitRequest();
    $request->setLaravelSession(app('session.store'));

    expect(ConsoleExit::href($request))->toBeNull();
});

it('renders the control into the app panel chrome with the session realm', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Solstice Retail', 'slug' => 'solstice']);
    $user = federatedConsoleUser($workspace);

    $this->actingAs($user);

    $response = $this->get('/app/'.$workspace->slug);
    $response->assertSuccessful();

    $html = (string) $response->getContent();

    // The control is present, and its href is the whole URL — read out of the
    // rendered markup rather than rebuilt here, so the assertion is about what a
    // browser would actually follow. `&` arrives HTML-escaped in the attribute
    // (correctly) and decodes to the query separator the DOM reports.
    expect($html)->toContain('data-platform-console-exit');

    preg_match('/<a\b[^>]*data-platform-console-exit[^>]*>/', $html, $anchor);
    expect($anchor)->not->toBeEmpty();

    preg_match('/href="([^"]+)"/', $anchor[0], $href);

    expect(html_entity_decode($href[1] ?? '', ENT_QUOTES))->toBe(
        // The realm the session federated through, and the path this request
        // arrived on (the test client's `/app/<slug>`, i.e. without the `/crm`
        // edge prefix the deployed nginx adds).
        'https://console-crm-uat.xgate.com/console/?realm=solstice&returnTo=%2Fapp%2Fsolstice',
    );
});

it('renders no control when no console is declared', function (): void {
    config()->set('relaticle.platform.console_url', null);

    $workspace = Workspace::factory()->create(['slug' => 'solstice']);
    $this->actingAs(federatedConsoleUser($workspace));

    $response = $this->get('/app/'.$workspace->slug);
    $response->assertSuccessful();

    expect((string) $response->getContent())->not->toContain('data-platform-console-exit');
});
