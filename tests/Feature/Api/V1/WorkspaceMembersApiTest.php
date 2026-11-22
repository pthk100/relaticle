<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Api\V1\WorkspaceMembersController;
use App\Models\User;

mutates(WorkspaceMembersController::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create([
        'name' => 'Mint Suwan',
        'email' => 'mint@example.com',
    ]);

    $this->workspace = $this->owner->personalWorkspace();

    $newToken = $this->owner->createToken('test', ['read']);
    $newToken->accessToken->forceFill(['workspace_id' => $this->workspace->getKey()])->save();

    $this->token = $newToken->plainTextToken;
});

it('lists the owner and every member of the token workspace, and nobody from another workspace', function (): void {
    $member = User::factory()->create([
        'name' => 'Ada Member',
        'email' => 'ada@example.com',
    ]);

    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $outsider = User::factory()->withPersonalWorkspace()->create([
        'name' => 'Otto Outsider',
        'email' => 'otto@example.com',
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/workspaces/{$this->workspace->slug}/members")
        ->assertOk();

    $members = collect($response->json('data'));

    expect($members->pluck('email')->all())
        ->toBe(['mint@example.com', 'ada@example.com'])
        ->not->toContain('otto@example.com')
        ->not->toContain($outsider->email);

    expect($members->firstWhere('email', 'mint@example.com')['role'])->toBe('owner');
    expect($members->firstWhere('email', 'ada@example.com')['role'])->toBe('member');
});

it('returns only id, name, email and role for each member', function (): void {
    $member = User::factory()->create();

    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Viewer->value]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/workspaces/{$this->workspace->slug}/members")
        ->assertOk();

    $members = collect($response->json('data'));

    expect($members)->toHaveCount(2);

    foreach ($members as $listed) {
        expect(array_keys($listed))->toBe(['id', 'name', 'email', 'role']);
    }

    expect($members->firstWhere('email', $member->email)['role'])->toBe('viewer');
});

it('does not list the owner twice when they also hold a pivot row', function (): void {
    $this->workspace->users()->attach($this->owner, ['role' => WorkspaceRole::Admin->value]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/workspaces/{$this->workspace->slug}/members")
        ->assertOk();

    $members = collect($response->json('data'));

    expect($members)->toHaveCount(1);
    expect($members->first())->toMatchArray([
        'id' => $this->owner->getKey(),
        'role' => 'owner',
    ]);
});

it('refuses a slug that is not the token workspace', function (): void {
    $otherOwner = User::factory()->withPersonalWorkspace()->create([
        'name' => 'Other Owner',
        'email' => 'other@example.com',
    ]);

    $otherWorkspace = $otherOwner->personalWorkspace();

    $this->withToken($this->token)
        ->getJson("/api/v1/workspaces/{$otherWorkspace->slug}/members")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not belong to this workspace.');
});

it('refuses a token without read, the ability records.view grants', function (): void {
    $newToken = $this->owner->createToken('write-only', ['create']);
    $newToken->accessToken->forceFill(['workspace_id' => $this->workspace->getKey()])->save();

    $this->withToken($newToken->plainTextToken)
        ->getJson("/api/v1/workspaces/{$this->workspace->slug}/members")
        ->assertForbidden();
});

it('does not list the platform identity as a member a caller may name', function (): void {
    $serviceUser = User::factory()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'The platform',
    ]);
    $this->workspace->users()->attach($serviceUser, ['role' => WorkspaceRole::Member->value]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/workspaces/{$this->workspace->slug}/members")
        ->assertOk();

    // This directory exists so a caller can pick a task assignee or an account owner.
    // The platform account is neither, and its id is not a thing a person should be
    // handed to name.
    expect(collect($response->json('data'))->pluck('email')->all())
        ->toBe(['mint@example.com'])
        ->not->toContain('platform@relaticle.invalid');
});
