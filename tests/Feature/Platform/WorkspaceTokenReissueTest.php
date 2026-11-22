<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;

/**
 * Credential reissue for an existing workspace (fork addition — see NOTICE;
 * marketing-platform cards t_659436f5, t_ca323f91).
 *
 * Two properties are under test, and they are different questions:
 *
 * 1. The credential must be USABLE — its holder has to belong to the workspace it is
 *    pinned to, or every workspace-scoped route answers 403 with nothing noticing
 *    (t_659436f5).
 * 2. The credential must act as the PLATFORM, not as the tenant's owner — a token
 *    minted for a person makes every platform write look like that person's own edit
 *    (t_ca323f91, docs/specs/crm-platform-write-attribution.decision.md).
 *
 * The caller's identity is never an input: the target is the configured service
 * address, so "the caller names another identity" is refusal-by-being-ignored rather
 * than a 422.
 *
 * One authenticated API call per test on purpose: the Sanctum guard caches the
 * resolved user for the life of the test application, so a second `withToken()` in
 * the same test would still be authenticated as the first one.
 */
beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');

    $this->serviceUser = User::factory()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'The platform',
    ]);
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

it('reissues a credential that acts as the platform identity and retires the previous platform token', function () use ($orphanedWorkspace): void {
    [, $workspace] = $orphanedWorkspace();

    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token')
        ->assertCreated();

    $issued = $response->json('data.token');

    expect($issued)->toBeString()->not->toBeEmpty()
        // The acting identity is the configured platform account — NOT the
        // workspace's owner, which is the whole point of t_ca323f91.
        ->and($response->json('data.actor_email'))->toBe('platform@relaticle.invalid')
        ->and($response->json('data.id'))->toBe($workspace->getKey())
        // The superseded platform token was retired in the same call.
        ->and($response->json('data.retired'))->toBe(1);

    // The replacement authenticates AND acts as the platform — which is what makes
    // every row it writes carry a true `creator_id`.
    $this->withToken($issued)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.email', 'platform@relaticle.invalid');

    // Exactly one live platform-minted row remains, held by the service identity.
    expect(
        PersonalAccessToken::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('name', 'platform:solstice')
            ->pluck('tokenable_id')
            ->all(),
    )->toBe([$this->serviceUser->getKey()]);
});

it('mints for the platform identity even when the caller names another identity', function (): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    $member = User::factory()->create(['email' => 'named@solstice.test']);
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    // Neither a body `owner_email` nor a body `user_id` moves the target: a tenant
    // credential must never act as an arbitrary account the caller names. The
    // workspace-owned member is the dangerous case — it BELONGS here, so a naive
    // `belongsToWorkspace()` gate would accept it.
    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token', [
            'owner_email' => 'attacker@elsewhere.test',
            'user_id' => $member->getKey(),
        ])
        ->assertCreated();

    expect($response->json('data.actor_email'))->toBe('platform@relaticle.invalid')
        ->and(User::query()->where('email', 'attacker@elsewhere.test')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $member->getKey())->count())->toBe(0);

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.email', 'platform@relaticle.invalid');
});

it('attaches the platform identity to a workspace that predates the sweep, once', function (): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    $workspace = Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace, ['role' => 'admin']);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token')
        ->assertCreated();

    $membership = $workspace->users()->whereKey($this->serviceUser->getKey())->first();

    expect($membership)->not->toBeNull()
        ->and($membership?->membership->role)->toBe(WorkspaceRole::Member->value);

    // Reissued again: no second membership row for the same identity.
    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token')
        ->assertCreated();

    expect($workspace->users()->whereKey($this->serviceUser->getKey())->count())->toBe(1);
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

it('answers 500 naming the command when the platform identity was never seeded', function (): void {
    $owner = User::factory()->create(['email' => 'admin@solstice.tenant.test']);
    Workspace::factory()->create(['slug' => 'solstice', 'user_id' => $owner->id]);
    $this->serviceUser->forceDelete();

    // Operator misconfiguration, not caller error: refuse to mint rather than fall
    // back to the owner, which is the defect this route exists to remove.
    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/solstice/token')
        ->assertStatus(500)
        ->assertJsonPath(
            'message',
            'Platform service user is not configured or does not exist. Run `php artisan platform:service-user`.',
        );
});
