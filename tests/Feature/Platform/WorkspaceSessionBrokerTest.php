<?php

declare(strict_types=1);

use App\Enums\WorkspaceSetupStep;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * The platform session broker (fork addition — see NOTICE; card t_5df176cd).
 *
 * Pins the two properties the console depends on: a workspace's owner is the
 * TENANT's own identity (derived from the signup), and a minted hand-off URL is a
 * single-use, short-lived path that lands the browser inside the panel as that
 * owner.
 */
beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');
});

it('refuses the session route without the platform key', function (): void {
    $this->postJson('/api/v1/workspaces/any/session', ['owner_email' => 'a@b.test'])
        ->assertUnauthorized();
});

it('404s for a workspace that does not exist', function (): void {
    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/nope/session', ['owner_email' => 'owner@tenant.test'])
        ->assertNotFound();
});

it('adopts the tenant owner on a workspace still owned by the platform service user', function (): void {
    $serviceUser = User::factory()->create(['email' => 'platform@relaticle.invalid']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $serviceUser->id]);

    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/session', [
            'owner_email' => 'admin@solstice.tenant.test',
            'owner_name' => 'Solstice Retail',
        ])
        ->assertOk();

    $path = $response->json('data.url');
    expect($path)->toBeString()->toStartWith('/auth/broker/');

    // Ownership moved off the shared service user — otherwise every tenant would
    // broker into the SAME session.
    $owner = User::query()->where('email', 'admin@solstice.tenant.test')->first();
    expect($owner)->not->toBeNull()
        ->and($workspace->fresh()->user_id)->toBe($owner->getKey())
        ->and($workspace->fresh()->hasUser($owner))->toBeTrue();
});

it('skips the panel first-run onboarding for a platform tenant', function (): void {
    $serviceUser = User::factory()->create(['email' => 'platform@relaticle.invalid']);
    $workspace = Workspace::factory()->create([
        'slug' => 'solstice',
        'user_id' => $serviceUser->id,
        'onboarding_step' => WorkspaceSetupStep::UseCase,
    ]);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/session', ['owner_email' => 'admin@solstice.tenant.test'])
        ->assertOk();

    // `null` is upstream's "onboarding complete" marker; a brand that already
    // described itself at signup must not be dropped into the CRM's wizard.
    expect($workspace->fresh()->onboarding_step)->toBeNull();
});

it('is idempotent — a second mint adopts the same owner', function (): void {
    $serviceUser = User::factory()->create(['email' => 'platform@relaticle.invalid']);
    $workspace = Workspace::factory()->create(['slug' => 'xgate', 'user_id' => $serviceUser->id]);

    $first = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/xgate/session', ['owner_email' => 'admin@xgate.tenant.test'])
        ->assertOk();
    $second = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/xgate/session', ['owner_email' => 'admin@xgate.tenant.test'])
        ->assertOk();

    expect(User::query()->where('email', 'admin@xgate.tenant.test')->count())->toBe(1)
        ->and($first->json('data.url'))->not->toBe($second->json('data.url'));
});

it('redeems the hand-off once and signs the browser in as that workspace owner', function (): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    $path = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/session', ['owner_email' => 'admin@solstice.tenant.test'])
        ->assertOk()
        ->json('data.url');

    $this->get($path)->assertRedirect(url(config('app.app_panel_path', 'app')));
    $this->assertAuthenticatedAs($owner->fresh());

    // Single-use: the same URL cannot sign anyone in a second time.
    $this->get($path)->assertForbidden();
});

it('expires an unused hand-off', function (): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);

    $path = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/session', ['owner_email' => 'admin@solstice.tenant.test'])
        ->assertOk()
        ->json('data.url');

    Cache::flush();

    $this->get($path)->assertForbidden();
});

it('creates the tenant owner as the workspace owner at provisioning time', function (): void {
    User::factory()->create(['email' => 'platform@relaticle.invalid']);

    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces', [
            'name' => 'Solstice Retail',
            'slug' => 'solstice',
            'owner_email' => 'admin@solstice.tenant.test',
            'owner_name' => 'Solstice Retail',
        ])
        ->assertCreated();

    expect($response->json('data.owner_email'))->toBe('admin@solstice.tenant.test');

    $owner = User::query()->where('email', 'admin@solstice.tenant.test')->first();
    expect($owner)->not->toBeNull()
        ->and(Workspace::query()->where('slug', 'solstice')->first()->user_id)->toBe($owner->getKey());
});

/*
 * Whether the broker MOVED ownership (marketing-platform card t_659436f5).
 *
 * This is the signal the orchestrator needs: the credential it holds was minted
 * for the PREVIOUS owner, and this adoption is the exact event that orphans it (the
 * token is not moved, and the fork holds only its hash). Reporting the move lets the
 * caller reissue in the same breath — POST /api/v1/workspaces/{slug}/token.
 */
it('reports whether the adoption actually moved ownership', function (): void {
    $serviceUser = User::factory()->create(['email' => 'platform@relaticle.invalid']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $serviceUser->id]);

    $adopted = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/session', ['owner_email' => 'admin@solstice.tenant.test'])
        ->assertOk();

    expect($adopted->json('data.owner_changed'))->toBeTrue()
        ->and($adopted->json('data.owner_email'))->toBe('admin@solstice.tenant.test');

    // Already owned by that identity: nothing moved, so nothing has to be reissued.
    $again = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/session', ['owner_email' => 'admin@solstice.tenant.test'])
        ->assertOk();

    expect($again->json('data.owner_changed'))->toBeFalse();
});
