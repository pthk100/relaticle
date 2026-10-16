<?php

declare(strict_types=1);

use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;

mutates(CreateWorkspace::class, WorkspacePolicy::class);

it('allows a fourth workspace under the default cap', function (): void {
    $user = User::factory()->create();

    Workspace::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $this->get(route('filament.app.tenant.registration'))->assertSuccessful();
});

it('exempts the platform service user from the owned-workspace cap', function (): void {
    // The service user owns one workspace per tenant, so the human anti-abuse cap
    // must never apply to it — otherwise the whole platform is silently capped at
    // max_owned_per_user tenants and the FATAL relaticle provisioning step 403s
    // from tenant #(cap+1) on. See WorkspacePolicy::create.
    config()->set('relaticle.workspaces.max_owned_per_user', 2);
    config()->set('relaticle.platform.service_user_email', 'platform@relaticle.invalid');

    $serviceUser = User::factory()->create(['email' => 'platform@relaticle.invalid']);

    Workspace::factory()->count(5)->create(['user_id' => $serviceUser->id]);

    $this->actingAs($serviceUser);

    expect($serviceUser->refresh()->ownedWorkspaces()->count())->toBe(5);

    $this->get(route('filament.app.tenant.registration'))->assertSuccessful();
});

it('explains the workspace limit instead of returning a bare 404', function (): void {
    // A low cap keeps the fixture small; the point is the behavior at the cap,
    // whatever the configured number happens to be.
    config()->set('relaticle.workspaces.max_owned_per_user', 3);

    $user = User::factory()->create();

    Workspace::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    expect($user->refresh()->ownedWorkspaces()->count())->toBe(3);

    $this->get(route('filament.app.tenant.registration'))
        ->assertRedirect()
        ->assertSessionHas('filament.notifications');
});
