<?php

declare(strict_types=1);

use App\Console\Commands\EnsurePlatformServiceUserCommand;
use App\Models\Opportunity;
use App\Models\User;

mutates(EnsurePlatformServiceUserCommand::class);

it('seeds the platform service user under a name that reads as the system, not a colleague', function (): void {
    $this->artisan('platform:service-user')->assertSuccessful();

    $user = User::query()
        ->where('email', config('relaticle.platform.service_user_email'))
        ->sole();

    expect($user->name)->toBe('The platform');
});

it('renames an existing service user in place instead of creating a second one', function (): void {
    $legacy = User::factory()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'Platform Provisioning',
    ]);

    $this->artisan('platform:service-user')->assertSuccessful();
    $this->artisan('platform:service-user')->assertSuccessful();

    $serviceUsers = User::query()
        ->where('email', config('relaticle.platform.service_user_email'))
        ->get();

    expect($serviceUsers)->toHaveCount(1)
        ->and($serviceUsers->first()?->is($legacy))->toBeTrue()
        ->and($serviceUsers->first()?->name)->toBe('The platform');
});

it('keeps the surface label and the seeded account name in step', function (): void {
    // The surface never prints the account's own name for a projected row (an operator may
    // rename that account), so the two words are kept equal by this guard rather than by
    // one of them reaching for the other at render time.
    expect(config('relaticle.platform.service_user_name'))->toBe(User::PLATFORM_SERVICE_USER_LABEL);
});

it('keeps the rows the service user authored attributed to it across the rename', function (): void {
    $serviceUser = User::factory()->withWorkspace()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'Platform Provisioning',
    ]);

    $opportunity = Opportunity::factory()->recycle([$serviceUser->currentWorkspace])->create([
        'creator_id' => $serviceUser->getKey(),
    ]);

    $this->artisan('platform:service-user')->assertSuccessful();

    expect($opportunity->fresh()?->creator_id)->toBe($serviceUser->getKey());
});
