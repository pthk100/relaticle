<?php

declare(strict_types=1);

namespace Tests\Feature\LifecycleStage;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\LifecycleStage\LifecycleStage;
use App\Support\LifecycleStage\LifecycleStageGuard;
use App\Support\LifecycleStage\LifecycleStageProjection;
use App\Support\LifecycleStage\LifecycleStageProjector;
use App\Support\LifecycleStage\LifecycleStageReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

mutates(
    LifecycleStage::class,
    LifecycleStageGuard::class,
    LifecycleStageProjection::class,
    LifecycleStageProjector::class,
    LifecycleStageReconciler::class,
);

/**
 * `lifecycle_stage` is DERIVED, NEVER AUTHORED (t_7273de85).
 *
 * These tests pin the two halves the card asks for separately: the derivation
 * itself (all four cases, and the two that must not collapse into each other), and
 * the mechanism that keeps a human's stage move inside the CRM from leaving the
 * field behind - including the case where the value was written behind the
 * application's back.
 */
beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    Sanctum::actingAs($this->user);

    $this->personId = $this->postJson('/api/v1/people', ['name' => 'Lifecycle Subject'])
        ->assertCreated()
        ->json('data.id');
});

/**
 * The contact's `lifecycle_stage` as the CRM stores it: the option NAME, which is
 * what the field displays and what an operator reads.
 */
function stageOf(People|string $person): ?string
{
    $person = is_string($person)
        ? People::query()->withoutGlobalScope(WorkspaceScope::class)->findOrFail($person)
        : $person;

    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $person->workspace_id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::LIFECYCLE_STAGE->value)
        ->firstOrFail();

    $person->loadMissing('customFieldValues');

    $value = $person->customFieldValues
        ->first(fn ($row): bool => (string) $row->custom_field_id === (string) $field->getKey());

    if ($value === null) {
        return null;
    }

    // A single-choice value is the option ID; the answer a reader sees is the
    // option's name. Accept a raw label too, so this helper reports what is stored
    // rather than crashing on the one shape it did not expect.
    $stored = (string) ($value->string_value ?? $value->integer_value ?? '');

    if ($stored === '') {
        return null;
    }

    $name = DB::table('custom_field_options')
        ->where('custom_field_id', $field->getKey())
        ->where(fn ($query) => $query->where('id', $stored)->orWhere('name', $stored))
        ->value('name');

    return is_string($name) ? $name : null;
}

/**
 * Create an opportunity for the subject with a stage, through the CRM's own REST
 * API - the same funnel a person dragging a card uses.
 */
function opportunity(array $attributes = [], ?string $stage = null): array
{
    $body = array_merge([
        'name' => 'Deal for the lifecycle subject',
        'contact_id' => test()->personId,
    ], $attributes);

    if ($stage !== null) {
        $body['custom_fields'] = ['stage' => $stage];
    }

    return test()->postJson('/api/v1/opportunities', $body)->assertCreated()->json('data');
}

it('derives the four stages, in the precedence the card states', function (array $stages, string $expected): void {
    expect(LifecycleStage::fromOpportunityStages($stages))->toBe($expected);
})->with([
    'no opportunities at all -> Lead' => [[], LifecycleStage::LEAD],
    'an open deal -> Opportunity' => [['Prospecting'], LifecycleStage::OPPORTUNITY],
    'a won deal -> Customer' => [['Closed Won'], LifecycleStage::CUSTOMER],
    'only lost deals -> Lost' => [['Closed Lost', 'Closed Lost'], LifecycleStage::LOST],
    'won outranks an open deal' => [['Prospecting', 'Closed Won'], LifecycleStage::CUSTOMER],
    'open outranks lost' => [['Closed Lost', 'Negotiation/Review'], LifecycleStage::OPPORTUNITY],
    'won outranks everything' => [['Closed Lost', 'Prospecting', 'Closed Won'], LifecycleStage::CUSTOMER],
    // An opportunity whose stage is unset is not a closed one.
    'a deal with no stage -> Opportunity' => [[null], LifecycleStage::OPPORTUNITY],
    // The comparison is case-folded: the option is what an operator reads.
    'labels are matched case-insensitively' => [['closed won'], LifecycleStage::CUSTOMER],
]);

it('offers exactly the four values as a select', function (): void {
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', PeopleField::LIFECYCLE_STAGE->value)
        ->firstOrFail();

    $options = CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();

    expect($field->type)->toBe('select')
        ->and($options)->toBe(['lead', 'opportunity', 'customer', 'lost'])
        // Undeletable: a workspace that dropped the derivation's target would stop
        // projecting silently.
        ->and((bool) $field->system_defined)->toBeTrue()
        ->and($field->settings->visible_in_list)->toBeTrue();
});

it('lands a new contact on Lead without anybody writing it', function (): void {
    expect(stageOf($this->personId))->toBe('lead');
});

it('follows a stage move made inside the CRM, and back again', function (): void {
    $deal = opportunity(stage: 'Prospecting');

    expect(stageOf($this->personId))->toBe('opportunity');

    // The drag: same REST endpoint the CRM's own UI posts to.
    $this->patchJson("/api/v1/opportunities/{$deal['id']}", [
        'custom_fields' => ['stage' => 'Closed Won'],
    ])->assertOk();

    expect(stageOf($this->personId))->toBe('customer');

    $this->patchJson("/api/v1/opportunities/{$deal['id']}", [
        'custom_fields' => ['stage' => 'Closed Lost'],
    ])->assertOk();

    expect(stageOf($this->personId))->toBe('lost');
});

it('recomputes when the deal that justified the stage is deleted', function (): void {
    $deal = opportunity(stage: 'Prospecting');
    expect(stageOf($this->personId))->toBe('opportunity');

    $this->deleteJson("/api/v1/opportunities/{$deal['id']}")->assertNoContent();

    expect(stageOf($this->personId))->toBe('lead');
});

it('refuses a hand edit of the derived stage', function (): void {
    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['lifecycle_stage' => 'customer'],
    ])->assertUnprocessable()->assertInvalid(['custom_fields.lifecycle_stage']);

    // The refusal is not "applied then reverted": nothing was stored.
    expect(stageOf($this->personId))->toBe('lead');
});

it('leaves ordinary contact edits alone — an omitted stage is not a cleared stage', function (): void {
    opportunity(stage: 'Closed Won');

    $this->patchJson("/api/v1/people/{$this->personId}", [
        'custom_fields' => ['job_title' => 'Head of Pipeline'],
    ])->assertOk();

    expect(stageOf($this->personId))->toBe('customer');
});

/**
 * The bypass every test below needs: write the value straight into the value row,
 * the way psql, a restore, or a projection that died on a quiet contact does.
 *
 * It fires nothing. `custom_field_values` has no timestamps and no audit trigger,
 * so this write leaves no trace any observer or cursor can see — which is the bug
 * t_885ca3bf is about.
 */
function stageBehindTheApplication(string $personId, string $optionName): void
{
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('entity_type', 'people')
        ->where('code', PeopleField::LIFECYCLE_STAGE->value)
        ->firstOrFail();

    $optionId = DB::table('custom_field_options')
        ->where('custom_field_id', $field->getKey())
        ->where('name', $optionName)
        ->value('id');

    DB::table('custom_field_values')
        ->where('custom_field_id', $field->getKey())
        ->where('entity_id', $personId)
        ->update(['string_value' => $optionId]);
}

it('overwrites a stage written behind the application, on the next reconciliation', function (): void {
    stageBehindTheApplication($this->personId, 'customer');

    expect(stageOf($this->personId))->toBe('customer');

    $result = app(LifecycleStageReconciler::class)->full();

    expect($result['changed'])->toBeGreaterThanOrEqual(1)
        ->and(stageOf($this->personId))->toBe('lead');
});

it('corrects a value written behind the application on the next five-minute tick', function (): void {
    // The DEV050 fixture, reproduced: a live deal, corrupted value, and a contact
    // nobody has touched for six days - the deal row AND its audit trail. The cursor
    // is written to NOW, so this is a real cursor tick rather than the first-run
    // fallback, and both of the old tick's legs are outside their window. That is
    // contact B, the one the old tick could not see.
    opportunity(stage: 'Prospecting');

    DB::table('opportunities')->update(['updated_at' => now()->subDays(6)]);
    DB::table('activity_log')->update(['created_at' => now()->subDays(6)]);

    $dealTouchedAt = DB::table('opportunities')->max('updated_at');
    $activityRows = DB::table('activity_log')->count();

    DB::table('platform_reconcile_cursors')->updateOrInsert(
        ['name' => LifecycleStageReconciler::CURSOR_NAME],
        ['value' => now()->toDateTimeString(), 'updated_at' => now()],
    );

    stageBehindTheApplication($this->personId, 'customer');

    expect(stageOf($this->personId))->toBe('customer')
        // The old tick's two legs are blind to this write, asserted rather than
        // assumed: the opportunity timestamp did not move and no activity row exists.
        ->and(DB::table('opportunities')->max('updated_at'))->toBe($dealTouchedAt)
        ->and(DB::table('activity_log')->count())->toBe($activityRows);

    $result = app(LifecycleStageReconciler::class)->incremental();

    expect($result['mode'])->toBe('incremental')
        ->and($result['changed'])->toBeGreaterThanOrEqual(1)
        ->and(stageOf($this->personId))->toBe('opportunity')
        // The repair is a projection, never a human edit: it must not appear in the
        // contact's timeline (LifecycleStageProjector::store()).
        ->and(DB::table('activity_log')->count())->toBe($activityRows);
});

it('gives lead to a contact whose stage row was deleted outright', function (): void {
    // The address-book-only contact: no deals, so `lead`. It appears in neither
    // comparison map (no value row after the delete, no opportunity ever), which is
    // the case the drift set has to reach explicitly.
    $personId = $this->postJson('/api/v1/people', ['name' => 'Address Book Only'])
        ->assertCreated()
        ->json('data.id');

    expect(stageOf($personId))->toBe('lead');

    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', PeopleField::LIFECYCLE_STAGE->value)
        ->firstOrFail();

    DB::table('custom_field_values')
        ->where('custom_field_id', $field->getKey())
        ->where('entity_id', $personId)
        ->delete();

    expect(stageOf($personId))->toBeNull();

    $result = app(LifecycleStageReconciler::class)->incremental();

    expect($result['changed'])->toBeGreaterThanOrEqual(1)
        ->and(stageOf($personId))->toBe('lead');
});

it('is idempotent — a second sweep corrects nothing', function (): void {
    opportunity(stage: 'Closed Won');

    $reconciler = app(LifecycleStageReconciler::class);

    $reconciler->full();

    expect($reconciler->incremental()['changed'])->toBe(0)
        ->and($reconciler->full()['changed'])->toBe(0);
});

it('corrects drift whether or not a cursor was ever written', function (): void {
    opportunity(stage: 'Prospecting');

    $reconciler = app(LifecycleStageReconciler::class);

    DB::table('platform_reconcile_cursors')->where('name', LifecycleStageReconciler::CURSOR_NAME)->delete();

    $first = $reconciler->incremental();

    expect($first['mode'])->toBe('incremental')
        ->and($first['cursor'])->not->toBeNull();

    // The cursor is written, and the tick is not gated by it: a value corrupted with
    // the cursor in place is still corrected on the next one.
    stageBehindTheApplication($this->personId, 'customer');

    $second = $reconciler->incremental();

    expect($second['changed'])->toBeGreaterThanOrEqual(1)
        ->and(stageOf($this->personId))->toBe('opportunity');
});

it('defers the workspaces it cannot reach instead of overrunning the tick', function (): void {
    // A second workspace, so there is something to defer. Creating a workspace seeds
    // the platform field set, so both are candidates.
    User::factory()->withPersonalWorkspace()->create();

    $result = app(LifecycleStageReconciler::class)->incremental(budget: 0);

    // The budget is checked before a workspace is started, and the first one is
    // always taken: a tick that could not afford a single workspace would never make
    // progress. Everything after it is reported, not silently dropped.
    expect($result['workspaces'])->toBe(1)
        ->and($result['deferred'])->toBeGreaterThanOrEqual(1);

    $complete = app(LifecycleStageReconciler::class)->incremental();

    expect($complete['deferred'])->toBe(0)
        ->and($complete['workspaces'])->toBeGreaterThanOrEqual(2);
});

it('survives a tenant id that has no workspace, instead of aborting the sweep', function (): void {
    // A deprovisioned workspace leaves custom_fields rows behind under a tenant id with
    // no workspaces row. Measured on DEV050: the pass threw ModelNotFoundException on the
    // first orphan and every later workspace went uncorrected.
    $orphan = (string) Str::ulid();

    DB::table('custom_fields')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $orphan,
        'entity_type' => 'people',
        'code' => PeopleField::LIFECYCLE_STAGE->value,
        'name' => 'Lifecycle Stage',
        'type' => 'select',
        'sort_order' => 21,
        'active' => true,
        'system_defined' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    opportunity(stage: 'Closed Won');

    $result = app(LifecycleStageReconciler::class)->full();

    expect($result['workspaces'])->toBeGreaterThanOrEqual(1)
        ->and(stageOf($this->personId))->toBe('customer');
});

it('offers origins only — a channel is not a lead source', function (): void {
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', PeopleField::LEAD_SOURCE->value)
        ->firstOrFail();

    $options = CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();

    // New workspaces get the origins only: a channel value cannot be written at all.
    expect($options)->toBe(array_keys(PeopleField::LEAD_SOURCE_OPTIONS))
        ->and($options)->not->toContain('LINE')
        ->and($options)->not->toContain('WhatsApp')
        ->and($options)->not->toContain('Web widget');
});
