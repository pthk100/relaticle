<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Actions\Platform\WriteConsentMirror;
use App\Http\Controllers\Api\V1\ConsentMirrorController;
use App\Http\Middleware\EnsurePlatformKey;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * THE PLATFORM'S MIRROR ENDPOINT (t_a8dd1bae).
 *
 * The five mirror fields are read-only for tenant callers, so the projection needs
 * its own platform-key-guarded door. These tests hold both halves: the door refuses
 * a caller without the key, and it refuses a value the workspace's select does not
 * offer — a silent drop would put a code in the CRM that no select can render.
 */
mutates(ConsentMirrorController::class, WriteConsentMirror::class, EnsurePlatformKey::class);

beforeEach(function (): void {
    config()->set('relaticle.platform.api_key', 'test-platform-key');

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Mirror Subject']);
});

/**
 * @param  array<string, mixed>  $values
 */
function postConsentMirror(string $workspaceId, string $personId, array $values, ?string $key = 'test-platform-key')
{
    $headers = $key === null ? [] : ['X-Platform-Key' => $key];

    return test()->postJson('/api/v1/platform/consent-mirror', [
        'workspace' => $workspaceId,
        'person' => $personId,
        'values' => $values,
    ], $headers);
}

/**
 * @return array<string, mixed>
 */
function mirrorValues(string $personId): array
{
    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($personId);
    $person->loadMissing('customFieldValues.customField');

    return $person->customFieldValues
        ->mapWithKeys(fn ($value): array => [(string) $value->customField?->code => $value->getValue()])
        ->all();
}

it('refuses a caller without the platform key', function (): void {
    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), ['marketing_optin' => 'declined'], null)
        ->assertUnauthorized();

    expect(mirrorValues($this->person->getKey())['marketing_optin'] ?? null)->toBeNull();
});

it('refuses a wrong platform key', function (): void {
    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), ['marketing_optin' => 'declined'], 'nope')
        ->assertUnauthorized();
});

it('writes the five codes onto the contact', function (): void {
    $response = postConsentMirror($this->workspace->getKey(), $this->person->getKey(), [
        'marketing_optin' => 'declined',
        'dnc' => true,
        'optout_reason' => 'too_frequent',
        'jurisdiction' => 'HK PDPO',
        'lawful_basis' => 'consent',
    ])->assertOk();

    expect($response->json('data.authority'))->toBe('platform_consent_store');

    $stored = mirrorValues($this->person->getKey());
    expect($stored['marketing_optin'])->toBe('declined')
        ->and($stored['optout_reason'])->toBe('too_frequent')
        ->and($stored['jurisdiction'])->toBe('HK PDPO')
        ->and($stored['lawful_basis'])->toBe('consent')
        ->and((bool) $stored['dnc'])->toBeTrue();
});

it('keeps all four opt-in values — not_asked is not collapsed to a boolean', function (): void {
    foreach (['granted', 'declined', 'unknown', 'not_asked'] as $value) {
        postConsentMirror($this->workspace->getKey(), $this->person->getKey(), ['marketing_optin' => $value])
            ->assertOk();

        expect(mirrorValues($this->person->getKey())['marketing_optin'])->toBe($value);
    }
});

it('refuses a value the workspace select does not offer', function (): void {
    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), [
        'optout_reason' => 'because I said so',
    ])->assertUnprocessable();

    expect(mirrorValues($this->person->getKey())['optout_reason'] ?? null)->toBeNull();
});

it('refuses a code that is not a consent mirror', function (): void {
    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), [
        'job_title' => 'Smuggled',
    ])->assertUnprocessable();
});

it('refuses an unknown person, and writes nothing', function (): void {
    postConsentMirror($this->workspace->getKey(), '01h0000000000000000000000', ['marketing_optin' => 'declined'])
        ->assertUnprocessable();
});

it('refuses a different workspace, so the platform key cannot cross tenants', function (): void {
    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = $otherUser->personalWorkspace();

    postConsentMirror($otherWorkspace->getKey(), $this->person->getKey(), ['marketing_optin' => 'declined'])
        ->assertUnprocessable();
});

it('does not disturb the other contact fields', function (): void {
    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($this->person->getKey());

    // A non-mirror field, set the ordinary way.
    $person->update(['custom_fields' => ['job_title' => 'Head of Consent']]);

    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), [
        'marketing_optin' => 'declined',
    ])->assertOk();

    $stored = mirrorValues($this->person->getKey());
    expect($stored['job_title'])->toBe('Head of Consent')
        ->and($stored['marketing_optin'])->toBe('declined');
});

it('clears a field the platform sends as null instead of leaving it stale', function (): void {
    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), [
        'optout_reason' => 'too_frequent',
    ])->assertOk();

    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), [
        'optout_reason' => null,
    ])->assertOk();

    expect(mirrorValues($this->person->getKey())['optout_reason'] ?? null)->toBeNull();
});

it('records the write in the activity log like any other contact change', function (): void {
    postConsentMirror($this->workspace->getKey(), $this->person->getKey(), ['marketing_optin' => 'declined'])
        ->assertOk();

    // The point is that the platform write is not a hole in the audit trail: it goes
    // through the ordinary update path, not a raw INSERT.
    $logged = DB::table('activity_log')
        ->where('subject_id', $this->person->getKey())
        ->count();

    expect($logged)->toBeGreaterThan(0);
});
