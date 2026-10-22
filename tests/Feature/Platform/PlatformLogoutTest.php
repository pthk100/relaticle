<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Support\Platform\KeycloakEndSession;
use App\Support\Platform\PlatformConsole;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/**
 * The platform sign-out (fork addition — see NOTICE; card t_fc36fa36).
 *
 * Three properties, and each can fail independently:
 *
 * 1. `GET /auth/logout` REVOKES server-side: the `sessions` row is gone, not just
 *    the cookie — that is the difference between the contract's §3.3 "true
 *    revocation" and clearing a browser cookie. A dead session must answer
 *    `200 {"revoked":false}` (the orchestrator cannot follow a redirect).
 * 2. RP-initiated logout is built from the `id_token` retained at callback; the
 *    URL it produces must carry `id_token_hint`, `client_id` and a
 *    post-logout redirect the realm client registers.
 * 3. When the deployment declares a console, `/login` and the panel's own auth
 *    pages are 302s to it — and the tenant surface, the break-glass broker and
 *    the provisioning API keep working. Unset (stock self-host), upstream's pages
 *    stay. Both directions are asserted, because only one of them can be faked.
 */
beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');

    // The test client's host. `RedirectToPrimaryHost` 301s any host that is not
    // `APP_URL`'s, so the fixture host and APP_URL must agree or every request
    // measures the middleware instead of the sign-out.
    config()->set('app.url', 'http://relaticle.test');
});

// ── 1. server-side revocation ────────────────────────────────────────────────

it('revokes the session server-side: the sessions row is deleted, not just the cookie', function (): void {
    config()->set('session.driver', 'database');

    $workspace = Workspace::factory()->create(['slug' => 'solstice']);
    $user = User::factory()->create();
    $workspace->users()->attach($user->getKey(), ['role' => 'admin']);
    $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    $this->actingAs($user->fresh());

    // A request that persists a real session row (driver=database), and the
    // cookie that carries its session id.
    $first = $this->get('/app/'.$workspace->slug)->assertSuccessful();
    $cookie = collect($first->headers->getCookies())
        ->first(fn ($c): bool => $c->getName() === config('session.cookie'));
    expect($cookie)->not->toBeNull();

    $liveIds = DB::table('sessions')->pluck('id')->all();
    expect($liveIds)->not->toBeEmpty();

    // The SAME browser: the session cookie is replayed exactly as the console's
    // internal call would forward it. (`get`, not `getJson`: the test client
    // drops cookies on JSON requests unless `withCredentials` — and the cookie is
    // the whole point of this test.)
    $res = $this->withUnencryptedCookie((string) config('session.cookie'), $cookie->getValue())
        ->get('/auth/logout', ['Accept' => 'application/json']);

    expect($res->status())->toBe(200)
        ->and($res->json())->toMatchArray(['revoked' => true])
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');

    // The row that made the cookie replayable is gone. `invalidate()` mints a NEW
    // id for the signed-out browser, so a bare row count is not the assertion —
    // the LIVE ids must no longer exist.
    expect(array_intersect($liveIds, DB::table('sessions')->pluck('id')->all()))->toBe([]);
});

it('answers an already-dead session with 200 revoked:false instead of a redirect', function (): void {
    $res = $this->getJson('/auth/logout');

    $res->assertOk()->assertJson(['revoked' => false, 'end_session_url' => null]);
});

it('reports the realm end-session URL but does not depend on it', function (): void {
    $res = $this->withSession([
        KeycloakEndSession::SESSION_KEY => ['realm' => 'tenant-solstice', 'id_token' => 'header.payload.sig'],
    ])->getJson('/auth/logout');

    $res->assertOk()->assertJson(['revoked' => false]);

    $url = (string) $res->json('end_session_url');
    expect($url)->toStartWith('http://relaticle.test/realms/tenant-solstice/protocol/openid-connect/logout?');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['id_token_hint'])->toBe('header.payload.sig')
        ->and($query['client_id'])->toBe('relaticle-crm')
        ->and($query['post_logout_redirect_uri'])->toBe('http://relaticle.test/');
});

it('builds the end-session URL on the deployment origin, not the internal dial host', function (): void {
    // The served shape: APP_URL carries the /crm prefix, the realm endpoints live
    // at the ORIGIN — the same split KeycloakFederationController uses.
    config()->set('app.url', 'https://console-crm-uat.xgate.com/crm');

    $res = $this->withSession([
        KeycloakEndSession::SESSION_KEY => ['realm' => 'tenant-solstice', 'id_token' => 'a.b.c'],
    ])->getJson('https://console-crm-uat.xgate.com/auth/logout');

    $res->assertOk();
    expect((string) $res->json('end_session_url'))->toStartWith(
        'https://console-crm-uat.xgate.com/realms/tenant-solstice/protocol/openid-connect/logout?',
    )->toContain('post_logout_redirect_uri='.urlencode('https://console-crm-uat.xgate.com/crm/'));
});

// ── 2. RP-initiated logout, the browser shape ───────────────────────────────

it('?continue=1 exits to the realm end-session endpoint with id_token_hint', function (): void {
    $res = $this->withSession([
        KeycloakEndSession::SESSION_KEY => ['realm' => 'tenant-solstice', 'id_token' => 'a.b.c'],
    ])->get('/auth/logout?continue=1');

    $res->assertRedirect();
    $location = (string) $res->headers->get('Location');

    expect($location)->toContain('/realms/tenant-solstice/protocol/openid-connect/logout')
        ->and($location)->toContain('id_token_hint=a.b.c')
        ->and($location)->toContain('client_id=relaticle-crm')
        ->and($location)->toContain('post_logout_redirect_uri='.urlencode('http://relaticle.test/'));
});

it('?continue=1 falls back to the console when no federated sign-in was recorded', function (): void {
    config()->set('relaticle.platform.console_url', '/console/');

    $res = $this->get('/auth/logout?continue=1');

    $res->assertRedirect();
    expect((string) $res->headers->get('Location'))->toContain('/console');
});

// ── 3. /crm/login must not serve a tenant (contract §8 item 3) ──────────────

it('sends /login to the console when the deployment declares one', function (): void {
    config()->set('relaticle.platform.console_url', '/console/');

    expect(PlatformConsole::isConfigured())->toBeTrue();

    $res = $this->get('/login');
    expect((string) $res->headers->get('Location'))->toBe('http://relaticle.test/console');
});

it('keeps upstream behaviour for /login when no console is configured', function (): void {
    config()->set('relaticle.platform.console_url', null);

    expect(PlatformConsole::url())->toBeNull();

    $res = $this->get('/login');
    expect((string) $res->headers->get('Location'))->toContain('/app/login');
});

it('302s the panel login and password-reset pages to the console', function (): void {
    config()->set('relaticle.platform.console_url', '/console/');

    foreach (['/app/login', '/app/password-reset/request'] as $path) {
        $res = $this->get($path);
        expect((string) $res->headers->get('Location'))->toBe('http://relaticle.test/console', $path);
    }
});

it('still renders upstream\'s panel login page when no console is configured', function (): void {
    config()->set('relaticle.platform.console_url', null);

    $res = $this->get('/app/login');

    $res->assertSuccessful();
    // Filament renders the form's state paths; a redirected body has none.
    expect($res->getContent())->toContain('data.email');
});

it('does not touch a signed-in tenant\'s own surface or the break-glass broker', function (): void {
    config()->set('relaticle.platform.console_url', '/console/');

    // A signed-in tenant's panel is untouched — the closure is on the AUTH pages.
    $workspace = Workspace::factory()->create(['slug' => 'solstice']);
    $user = User::factory()->create();
    $workspace->users()->attach($user->getKey(), ['role' => 'admin']);
    $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    $this->actingAs($user->fresh());
    Filament::setTenant($workspace);
    $this->get('/app/'.$workspace->slug)->assertSuccessful();

    // Break-glass broker: still routed, and NOT answered by the console redirect.
    $broker = $this->get('/auth/broker/not-a-real-token');
    expect((string) $broker->headers->get('Location'))->not->toContain('/console');
});

it('leaves the provisioning API alone (the negative control)', function (): void {
    config()->set('relaticle.platform.console_url', '/console/');
    User::factory()->create(['email' => 'platform@relaticle.invalid']);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces', ['name' => 'Logout Probe', 'slug' => 'logout-probe'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'token']]);
});
