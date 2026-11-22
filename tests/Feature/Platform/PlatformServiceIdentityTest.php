<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\WorkspaceRole;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * The platform's credential acts as the platform (fork addition — see NOTICE;
 * marketing-platform card t_ca323f91, decision docs/specs/crm-platform-write-attribution.decision.md).
 *
 * The invariant under test is WHICH IDENTITY holds a platform-minted credential. A
 * token minted for the workspace's tenant owner is true to that owner but false
 * about the author: a projected row then renders as that person's own edit. Minting
 * for the configured service identity — attached to every workspace as a `member`,
 * which is all `SetApiWorkspaceContext` needs — makes `creator_id` true, and the
 * identity-based `⊙ The platform` mark (already shipped) then applies unchanged.
 */
beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');
    config()->set('relaticle.platform.service_user_name', 'The platform');
});

/** The configured identity, as the command seeds it. */
function platformIdentity(): User
{
    return User::query()
        ->where('email', config('relaticle.platform.service_user_email'))
        ->sole();
}

it('provisions a workspace owned by the tenant but credentialled as the platform', function (): void {
    $this->artisan('platform:service-user')->assertSuccessful();

    $response = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces', [
            'name' => 'Solstice Retail',
            'slug' => 'solstice',
            'owner_email' => 'admin@solstice.tenant.test',
            'owner_name' => 'Solstice Retail',
        ])
        ->assertCreated();

    $workspace = Workspace::query()->where('slug', 'solstice')->sole();
    $serviceUser = platformIdentity();

    // The workspace still belongs to the tenant: ownership is what the session broker
    // signs in as, and moving it to the platform would collapse every tenant into one
    // session.
    expect($response->json('data.owner_email'))->toBe('admin@solstice.tenant.test')
        // The CREDENTIAL, though, must act as the platform — and say so.
        ->and($response->json('data.actor_email'))->toBe('platform@relaticle.invalid');

    // Membership is what SetApiWorkspaceContext resolves `belongs` from, and it is
    // deliberately NOT admin: the API ability set comes from WorkspaceCapability::forOwner()
    // regardless, so the attached role only governs panel UI.
    $membership = $workspace->users()->whereKey($serviceUser->getKey())->first();
    expect($membership)->not->toBeNull()
        ->and($membership?->membership->role)->toBe(WorkspaceRole::Member->value);

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.attributes.email', 'platform@relaticle.invalid');
});

it('attributes a platform API write to the platform identity, so the CRM marks it as the platform', function (): void {
    $this->artisan('platform:service-user')->assertSuccessful();

    $token = $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces', [
            'name' => 'Solstice Retail',
            'slug' => 'solstice',
            'owner_email' => 'admin@solstice.tenant.test',
        ])
        ->assertCreated()
        ->json('data.token');

    $this->withToken($token)
        ->postJson('/api/v1/companies', ['name' => 'Probe Co'])
        ->assertCreated();

    $company = Company::query()->withoutGlobalScopes()->where('name', 'Probe Co')->sole();
    $serviceUser = platformIdentity();

    // Acceptance 1: creator_id is the service account, the source is still `api` (a
    // tenant's own integration writes keep that same source and must keep rendering as
    // the human who wrote them), and the identity-based accessor names the platform.
    expect($company->creator_id)->toBe($serviceUser->getKey())
        ->and($company->creation_source)->toBe(CreationSource::API)
        ->and($company->isSystemCreated())->toBeTrue()
        ->and($company->created_by)->toBe('⊙ The platform');
});

it('still credits the human who wrote through the tenant\'s own credential', function (): void {
    // Acceptance 2: the distinction a source-based rule would have destroyed. This
    // record is written over the SAME api source as the one above, by a person holding
    // the tenant's own token, and it must keep naming that person.
    $owner = User::factory()->withPersonalWorkspace()->create(['name' => 'B Boy Bat']);
    $workspace = $owner->personalWorkspace();

    $token = $owner->createToken('mcp:tenant-created', ['read', 'create', 'update', 'delete']);
    $token->accessToken->forceFill(['workspace_id' => $workspace->getKey()])->save();

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/companies', ['name' => 'Typed By A Person'])
        ->assertCreated();

    $company = Company::query()->withoutGlobalScopes()->where('name', 'Typed By A Person')->sole();

    expect($company->creator_id)->toBe($owner->getKey())
        ->and($company->creation_source)->toBe(CreationSource::API)
        ->and($company->isSystemCreated())->toBeFalse()
        ->and($company->created_by)->toBe('B Boy Bat');
});

it('attaches the platform identity when a workspace is adopted onto its tenant owner', function (): void {
    $serviceUser = User::factory()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'The platform',
    ]);
    $workspace = Workspace::factory()->create(['slug' => 'xgate', 'user_id' => $serviceUser->id]);

    $this->withHeader('X-Platform-Key', 'test-platform-key')
        ->postJson('/api/v1/workspaces/xgate/session', [
            'owner_email' => 'admin@xgate.tenant.test',
            'owner_name' => 'Xgate',
        ])
        ->assertOk();

    $adopted = $workspace->fresh();
    $membership = $adopted?->users()->whereKey($serviceUser->getKey())->first();

    expect($adopted?->user_id)->not->toBe($serviceUser->getKey())
        ->and($membership)->not->toBeNull()
        ->and($membership?->membership->role)->toBe(WorkspaceRole::Member->value);
});

it('sweeps every existing workspace and attaches nothing twice on a re-run', function (): void {
    $first = Workspace::factory()->create(['slug' => 'first']);
    $second = Workspace::factory()->create(['slug' => 'second']);

    $this->artisan('platform:service-user')->assertSuccessful();
    $serviceUser = platformIdentity();

    $rowsAfterFirstRun = DB::table('workspace_user')->orderBy('workspace_id')->orderBy('user_id')->get();

    expect($rowsAfterFirstRun->where('user_id', $serviceUser->getKey()))->toHaveCount(2);
    expect($first->users()->whereKey($serviceUser->getKey())->exists())->toBeTrue()
        ->and($second->users()->whereKey($serviceUser->getKey())->exists())->toBeTrue();

    $this->artisan('platform:service-user')->assertSuccessful();

    // Acceptance 3: a second run creates no second user, adds no second membership and
    // moves no row.
    expect(User::query()->where('email', config('relaticle.platform.service_user_email'))->count())->toBe(1)
        ->and(DB::table('workspace_user')->orderBy('workspace_id')->orderBy('user_id')->get()->toArray())
        ->toEqual($rowsAfterFirstRun->toArray());
});
