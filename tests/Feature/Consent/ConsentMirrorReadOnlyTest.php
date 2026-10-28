<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\ConsentMirror\ConsentMirrorGuard;
use Laravel\Sanctum\Sanctum;

/**
 * THE READ-ONLY INVARIANT (t_a8dd1bae, AC "read-only in the CRM").
 *
 * The five mirror fields are a projection of the platform's consent store. A human
 * must not be able to set them and have it stick — that is the dangerous direction
 * (a CRM row saying `granted` for somebody who withdrew), so these tests fail if
 * the guard is ever loosened back to "system_defined is just a flag".
 */
beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/people', ['name' => 'Consent Subject'])->assertCreated();
    $this->personId = $created->json('data.id');
});

/**
 * @return array<string, mixed>
 */
function contactFieldValues(string $workspaceId, string $personId): array
{
    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($personId);
    $person->loadMissing('customFieldValues.customField');

    return $person->customFieldValues
        ->mapWithKeys(fn ($value): array => [
            (string) $value->customField?->code => $value->getValue(),
        ])
        ->all();
}

it('refuses a human setting a consent mirror, and stores nothing', function (): void {
    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['marketing_optin' => 'granted'],
    ])->assertUnprocessable()->assertInvalid(['custom_fields.marketing_optin']);

    expect(contactFieldValues($this->workspace->getKey(), $this->personId)['marketing_optin'] ?? null)
        ->not->toBe('granted');
});

it('refuses every one of the five mirrors, not just the opt-in', function (string $code, mixed $value): void {
    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => [$code => $value],
    ])->assertUnprocessable();
})->with([
    ['marketing_optin', 'declined'],
    ['dnc', true],
    ['optout_reason', 'too_frequent'],
    ['jurisdiction', 'HK PDPO'],
    ['lawful_basis', 'consent'],
]);

it('leaves ordinary contact edits working — a payload that omits the mirrors is not an edit', function (): void {
    // This is the case that would break if the guard treated the null
    // `saveCustomFields()` writes for every omitted code as an attempt to clear it.
    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['job_title' => 'Chief Button Presser'],
    ])->assertOk();

    expect(contactFieldValues($this->workspace->getKey(), $this->personId)['job_title'])
        ->toBe('Chief Button Presser');
});

it('does not blank a mirror that already holds a value when another field is edited', function (): void {
    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($this->personId);

    // Seed a mirror the way the platform does.
    ConsentMirrorGuard::runAsPlatform(function () use ($person): void {
        $person->update(['custom_fields' => ['marketing_optin' => 'declined']]);
    });

    expect(contactFieldValues($this->workspace->getKey(), $this->personId)['marketing_optin'])->toBe('declined');

    // A human edit of an unrelated field must not disturb it.
    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['department' => 'Revenue'],
    ])->assertOk();

    expect(contactFieldValues($this->workspace->getKey(), $this->personId)['marketing_optin'])->toBe('declined');
});

it('accepts a write that does not change the value (idempotent re-save)', function (): void {
    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($this->personId);

    ConsentMirrorGuard::runAsPlatform(function () use ($person): void {
        $person->update(['custom_fields' => ['marketing_optin' => 'unknown']]);
    });

    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['marketing_optin' => 'unknown'],
    ])->assertOk();
});

it('lets the platform write through the guard', function (): void {
    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($this->personId);

    ConsentMirrorGuard::runAsPlatform(function () use ($person): void {
        $person->update(['custom_fields' => ['marketing_optin' => 'not_asked']]);
    });

    expect(contactFieldValues($this->workspace->getKey(), $this->personId)['marketing_optin'])
        ->toBe('not_asked');
});

it('restores the guard when a platform write throws', function (): void {
    try {
        ConsentMirrorGuard::runAsPlatform(function (): void {
            throw new \RuntimeException('boom');
        });
    } catch (\RuntimeException) {
        // expected
    }

    expect(ConsentMirrorGuard::isPlatformWrite())->toBeFalse();
});

it('names only the five consent mirrors as protected', function (): void {
    expect(ConsentMirrorGuard::protectedCodes())->toBe([
        'marketing_optin',
        'dnc',
        'optout_reason',
        'jurisdiction',
        'lawful_basis',
    ]);
});

it('leaves no temp payload behind when it refuses a write', function (): void {
    // `UsesCustomFields` keeps the payload in a STATIC keyed by spl_object_id and
    // only clears it after a successful write, so a refusal must not leak it: object
    // ids are reused, and a live PHP-FPM worker keeps statics across requests.
    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['dnc' => true],
    ])->assertUnprocessable();

    $reflection = new \ReflectionProperty(\Relaticle\CustomFields\Models\Concerns\UsesCustomFields::class, 'tempCustomFields');
    $reflection->setAccessible(true);

    $payloads = $reflection->getValue();

    expect($payloads)->toBe([]);
});
