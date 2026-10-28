<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;

/**
 * Credential reissue for an existing workspace (fork addition — see NOTICE;
 * marketing-platform card t_659436f5).
 *
 * The property under test is the one that keeps a stored credential from
 * outliving its own authority: after a workspace is adopted onto a new owner, the
 * platform must be able to replace the token it holds, and the replacement must
 * act as the workspace's CURRENT owner — otherwise every workspace-scoped route
 * answers 403 "You do not belong to this workspace." with nothing noticing.
 *
 * One authenticated API call per test on purpose: the Sanctum guard caches the
 * resolved user for the life of the test application, so a second `withToken()`
 * in the same test would still be authenticated as the first one.
 */
beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');
});

/** Mint a token the way the platform's older runs did: pinned, name `platform:<slug>`. */
$legacyPlatformToken = function (User $holder, Workspace $workspace, string $name = 'platform:solstice'): string {
    $token = $holder->createToken($name, ['read']);
    $token->accessToken->fill(['workspace_id' => $workspace->getKey()])->save();

    return explode('|', $token->plainTextToken, 2)[1];
};

/** A workspace with an owner, and a credential held by someone the adoption left behind. */
$orphanedWorkspace = function () use ($legacyPlatformToken): array {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    // The credential the orchestrator already holds: minted for the identity that
    // owned the workspace BEFORE it was adopted. The adoption moved ownership off
    // that identity and dropped its membership — exactly t_956ba6b8's shape.
    $previousHolder = User::factory()->create(['email' => 'exit-walk@solstice.test']);
    $previousHolder->workspaces()->attach($workspace, ['role' => 'admin']);
    $deadToken = $legacyPlatformToken($previousHolder, $workspace);
    $workspace->users()->detach($previousHolder->getKey());

    return [$owner, $workspace, $previousHolder, $deadToken];
};

it('refuses the reissue route without the platform key', function (): void {
    $this->postJson('/api/v1/workspaces/any/token')->assertUnauthorized();
});

it('404s for a workspace that does not exist', function (): void {
    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/nope/token')
        ->assertNotFound();
});

it('refuses a credential whose holder no longer belongs to the workspace', function () use ($orphanedWorkspace): void {
    [, , , $deadToken] = $orphanedWorkspace();

    // The failure this route repairs, reproduced: the workspace no longer belongs
    // to the identity the stored token signs as.
    $this->withToken($deadToken)
        ->getJson('/api/v1/user')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not belong to this workspace.');
});

it('reissues a credential that acts as the workspace owner and retires the previous platform token', function () use ($orphanedWorkspace): void {
    [$owner, $workspace] = $orphanedWorkspace();

    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token')
        ->assertCreated();

    $issued = $response->json('data.token');
    expect($issued)->toBeString()->not->toBeEmpty()
        // The identity is the workspace's owner of record, never a caller input.
        ->and($response->json('data.owner_email'))->toBe('admin@solstice.tenant.test')
        ->and($response->json('data.id'))->toBe($workspace->getKey())
        // The superseded platform token was retired in the same call.
        ->and($response->json('data.retired'))->toBe(1);

    // The replacement authenticates AND acts as the tenant's own identity — the
    // invariant the provisioning seam asserts for a freshly minted credential.
    $this->withToken($issued)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.email', 'admin@solstice.tenant.test');

    // Exactly one live platform-minted row remains, and it is pinned to this
    // workspace: the dead one is gone rather than left for a later bug to re-adopt.
    expect(
        PersonalAccessToken::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('name', 'platform:solstice')
            ->pluck('tokenable_id')
            ->all(),
    )->toBe([$owner->getKey()]);
});

it('mints for the owner of record even when the caller names another identity', function (): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token', [
            'owner_email' => 'attacker@elsewhere.test',
        ])
        ->assertCreated();

    expect($response->json('data.owner_email'))->toBe('admin@solstice.tenant.test')
        ->and(User::query()->where('email', 'attacker@elsewhere.test')->exists())->toBeFalse();

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.email', 'admin@solstice.tenant.test');
});

it('leaves tokens it did not mint alone', function () use ($legacyPlatformToken): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    // A tenant's OWN panel/MCP token is not this seam's business: rotation is
    // scoped to the `platform:<slug>` name this fork's provisioning mints.
    $legacyPlatformToken($owner, $workspace, 'mcp:tenant-created');

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token')
        ->assertCreated();

    expect(
        PersonalAccessToken::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('name', 'mcp:tenant-created')
            ->count(),
    )->toBe(1);
});
