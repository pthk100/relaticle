<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->member = User::factory()->withWorkspace()->create();
    $this->actingAs($this->member);
    $this->workspace = $this->member->currentWorkspace;
    Filament::setTenant($this->workspace);

    // A deployment whose bootstrap ran before the account was renamed still holds this
    // name, so the surface has to read as the platform without depending on it.
    $this->serviceUser = User::factory()->create([
        'email' => config('relaticle.platform.service_user_email'),
        'name' => 'Platform Provisioning',
    ]);
});

it('shows a platform projected opportunity as the system in the list, not as a colleague', function (): void {
    $opportunity = Opportunity::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->serviceUser->getKey(),
        'creation_source' => CreationSource::API->value,
    ]);

    livewire(ListOpportunities::class)
        ->assertCanSeeTableRecords([$opportunity])
        ->assertSee('⊙ The platform')
        ->assertDontSee('Platform Provisioning');
});

it('shows a platform projected company as the system in the list, not as a colleague', function (): void {
    $company = Company::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->serviceUser->getKey(),
        'creation_source' => CreationSource::API->value,
    ]);

    livewire(ListCompanies::class)
        ->assertCanSeeTableRecords([$company])
        ->assertSee('⊙ The platform')
        ->assertDontSee('Platform Provisioning');
});

it('shows the platform as the record author on the opportunity page, not as a member', function (): void {
    $opportunity = Opportunity::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'creator_id' => $this->serviceUser->getKey(),
        'creation_source' => CreationSource::API->value,
    ]);

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertOk()
        ->assertSee('⊙ The platform')
        ->assertDontSee('Platform Provisioning');
});
