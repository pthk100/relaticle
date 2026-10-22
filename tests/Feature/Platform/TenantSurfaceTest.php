<?php

declare(strict_types=1);

use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Platform\TenantSurface;
use Filament\Facades\Filament;

/**
 * The tenant surface (fork addition — see NOTICE; card t_e07ff7ad).
 *
 * Two properties, and both directions matter:
 *
 * 1. A session that arrived through the platform's federated hand-off must see NO
 *    workspace-creation affordance and be refused the route — the platform creates
 *    the tenant's workspace, so a tenant-made one would sit outside the
 *    orchestrator's tenancy.
 * 2. A LOCAL user (upstream's own self-host onboarding) must keep it, and the
 *    platform's provisioning endpoint must keep working with X-Platform-Key. A
 *    change that only proved (1) could lock onboarding and still look green.
 */
beforeEach(function (): void {
    TenantSurface::forget();

    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');
});

/** A user whose identity came through the platform's federated hand-off. */
function federatedTenantSession(Workspace $workspace, string $sub = 'sub-1'): User
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

it('hides the create-workspace affordance from a federated tenant session', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Solstice Retail', 'slug' => 'solstice']);
    $user = federatedTenantSession($workspace);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    expect(TenantSurface::isTenantSession())->toBeTrue()
        ->and(CreateWorkspace::canView())->toBeFalse();

    // The route the tenant switcher's "Create Workspace" item points at.
    $this->get(route('filament.app.tenant.registration'))->assertNotFound();
});

it('renders no "create workspace" text anywhere in a tenant dashboard', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Solstice Retail', 'slug' => 'solstice']);
    $user = federatedTenantSession($workspace);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    // The switcher dropdown is rendered server-side, so the item's label is
    // measurable in the response body rather than only in a browser.
    $this->get('/app/'.$workspace->slug)
        ->assertSuccessful()
        ->assertDontSee('Create Workspace');
});

it('still offers create-workspace to a local user (upstream self-host onboarding)', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    expect(TenantSurface::isTenantSession())->toBeFalse()
        ->and(CreateWorkspace::canView())->toBeTrue();

    $this->get(route('filament.app.tenant.registration'))->assertSuccessful();
});

it('treats a social account on a non-tenant provider as a local user', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->socialAccounts()->create(['provider_name' => 'google', 'provider_id' => '1234567890']);

    $this->actingAs($user->fresh());
    Filament::setTenant($workspace);

    expect(TenantSurface::isTenantSession())->toBeFalse()
        ->and(CreateWorkspace::canView())->toBeTrue();
});

it('brands the tenant surface with the tenant name, not the product mark', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Solstice Retail', 'slug' => 'solstice']);
    $user = federatedTenantSession($workspace);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    expect(filament()->getBrandName())->toBe('Solstice Retail');

    $response = $this->get('/app/'.$workspace->slug)->assertSuccessful();

    $response->assertSee('Solstice Retail');

    $html = (string) $response->getContent();

    // The <title> is rendered across several lines ("Home / - / <brand>"), so it
    // is compared collapsed rather than as one literal string.
    preg_match('/<title>(.*?)<\/title>/s', $html, $matches);
    $renderedTitle = trim((string) preg_replace('/\s+/', ' ', $matches[1] ?? ''));

    // The <title> carries the name in place of the product mark…
    expect($renderedTitle)->toContain('Solstice Retail')
        // …and no rendered element of the tenant's page names the product.
        ->and($html)->not->toContain('Relaticle');
});

it('keeps the deployment name for a local user', function (): void {
    config()->set('app.name', 'Acme CRM');

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    expect(filament()->getBrandName())->toBe('Acme CRM');
});

it('still provisions a workspace for the platform key (the negative control)', function (): void {
    User::factory()->create(['email' => 'platform@relaticle.invalid']);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces', ['name' => 'New Tenant', 'slug' => 'new-tenant'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'new-tenant')
        ->assertJsonStructure(['data' => ['id', 'name', 'slug', 'token']]);
});

it('refuses the provisioning route to a federated session without the platform key', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'solstice']);
    $user = federatedTenantSession($workspace);

    $this->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->postJson('/api/v1/workspaces', ['name' => 'Tenant Made', 'slug' => 'tenant-made'])
        ->assertUnauthorized();

    expect(Workspace::query()->where('slug', 'tenant-made')->exists())->toBeFalse();
});
