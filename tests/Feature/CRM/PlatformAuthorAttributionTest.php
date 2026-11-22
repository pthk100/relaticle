<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

mutates(Opportunity::class, People::class, Company::class, Task::class, Note::class);

dataset('platform authored records', [
    'opportunity' => [Opportunity::class],
    'person' => [People::class],
    'company' => [Company::class],
    'task' => [Task::class],
    'note' => [Note::class],
]);

it('presents a record the platform wrote as the system, not as a colleague', function (string $model): void {
    $workspace = Workspace::factory()->create();

    // The account may still carry the human-sounding name a mismanaged bootstrap gave it:
    // the surface must read as the platform regardless of what the account is called.
    $serviceUser = User::factory()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'Platform Provisioning',
    ]);

    /** @var Opportunity|People|Company|Task|Note $record */
    $record = $model::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'creator_id' => $serviceUser->getKey(),
        'creation_source' => CreationSource::API->value,
    ]);

    expect($record->isSystemCreated())->toBeTrue()
        ->and($record->created_by)->toBe('⊙ The platform');
})->with('platform authored records');

it('names the member who wrote the record', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['name' => 'Nok Suwan']);

    $opportunity = Opportunity::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'creator_id' => $member->getKey(),
        'creation_source' => CreationSource::WEB->value,
    ]);

    expect($opportunity->isSystemCreated())->toBeFalse()
        ->and($opportunity->created_by)->toBe('Nok Suwan');
});

it('keeps the source label for a record the platform seeded', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['name' => 'Nok Suwan']);

    $company = Company::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'creator_id' => $member->getKey(),
        'creation_source' => CreationSource::SAMPLE->value,
    ]);

    expect($company->isSystemCreated())->toBeTrue()
        ->and($company->created_by)->toBe('⊙ Sample Data');
});
