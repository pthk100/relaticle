<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

/**
 * Platform workspace teardown (fork addition, see NOTICE; marketing-platform card
 * t_eec81db2).
 *
 * The properties under test are the ones a teardown report depends on: a DELETE
 * that removed a workspace answers 200, a DELETE of a slug that never existed
 * answers 404, and the tenant identity that owned the workspace does not outlive
 * it, except where the identity is shared, which the response says out loud.
 */
beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');
});

/** A workspace owned by its tenant's own admin address, the way provisioning makes one. */
$tenantWorkspace = function (string $slug = 'solstice'): array {
    $owner = User::factory()->create(['email' => 'admin@'.$slug.'.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => $slug, 'user_id' => $owner->getKey()]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    return [$owner, $workspace];
};

it('refuses the teardown route without the platform key', function (): void {
    $this->deleteJson('/api/v1/workspaces/any')->assertUnauthorized();
});

it('deletes the workspace and the tenant identity that owned it', function () use ($tenantWorkspace): void {
    [$owner, $workspace] = $tenantWorkspace();

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/solstice')
        ->assertOk()
        ->assertJsonPath('data.slug', 'solstice')
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.owner.email', 'admin@solstice.tenant.test')
        ->assertJsonPath('data.owner.deleted', true)
        ->assertJsonPath('data.owner.retained_because', null);

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse()
        ->and(User::query()->whereKey($owner->getKey())->exists())->toBeFalse();
});

it('answers 404, not 200, for a slug that never existed', function (): void {
    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/never-provisioned')
        ->assertNotFound()
        ->assertJsonPath('data.slug', 'never-provisioned')
        ->assertJsonPath('data.deleted', false);
});

it('distinguishes the delete from the teardown it repeats', function () use ($tenantWorkspace): void {
    $tenantWorkspace();

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/solstice')
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/solstice')
        ->assertNotFound()
        ->assertJsonPath('data.deleted', false);
});

it('keeps the shared platform service user', function () use ($tenantWorkspace): void {
    [$owner] = $tenantWorkspace();
    $owner->forceFill(['email' => 'platform@relaticle.invalid'])->save();

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/solstice')
        ->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.owner.deleted', false)
        ->assertJsonPath('data.owner.retained_because', 'it is the shared platform service user, which owns every workspace created without a named owner');

    expect(User::query()->whereKey($owner->getKey())->exists())->toBeTrue();
});

it('keeps an owner that is not this tenant own admin address', function (): void {
    $owner = User::factory()->create(['email' => 'jane@acme.test']);
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'user_id' => $owner->getKey()]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/acme')
        ->assertOk()
        ->assertJsonPath('data.owner.deleted', false)
        ->assertJsonPath('data.owner.retained_because', 'it is not this tenant\'s own admin address (admin@<slug>.<domain>), so it is a human or shared identity rather than one the platform minted');

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse()
        ->and(User::query()->whereKey($owner->getKey())->exists())->toBeTrue();
});

it('keeps an identity that still belongs to another workspace', function () use ($tenantWorkspace): void {
    [$owner] = $tenantWorkspace();

    $shared = Workspace::factory()->create(['slug' => 'shared-scope']);
    $owner->workspaces()->attach($shared, ['role' => 'member']);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->deleteJson('/api/v1/workspaces/solstice')
        ->assertOk()
        ->assertJsonPath('data.owner.deleted', false)
        ->assertJsonPath('data.owner.retained_because', 'it still owns or belongs to another workspace');

    expect(User::query()->whereKey($owner->getKey())->exists())->toBeTrue();
});
