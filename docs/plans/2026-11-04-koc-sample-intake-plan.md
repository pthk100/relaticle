# KOC sample intake to affiliate track

Plan for an implementer with no context for this repository. Every file path below is real, every
command is exact, and the expected output is stated so you can tell the difference between "it
worked" and "it silently did nothing".

This plan lives at `docs/plans/2026-11-04-koc-sample-intake-plan.md` in the codebase it
targets: `/Users/ai.parry/dev/relaticle-fork`.

---

## Goal

Turn the KOC sample application from an external Lark form into a workspace-scoped part of the app,
so a submission opens a review queue, a reviewer's approve or reject is the only way the record
advances, an approval creates the person and the opportunity, and a Partner Centre affiliation
(creator status, affiliate track id, commission rate, attributed results) hangs off that
opportunity.

## Current context and assumptions

**The repository.** `/Users/ai.parry/dev/relaticle-fork` is a modular monolith: Laravel 12-era
skeleton on PHP 8.5 and Filament 5, PostgreSQL only. The CRM domain (Company, People, Opportunity,
Task, Note) lives in `app/`. Subsystems live in `packages/` with a `Relaticle\*` namespace. Read
`AGENTS.md` before your first commit; it is 1146 lines and it is enforced by tests, not advisory.

**What exists today, and is reused rather than rebuilt:**

| Thing | Where | What it gives you |
|---|---|---|
| Opportunity record | `app/Models/Opportunity.php`, `app/Actions/Opportunity/CreateOpportunity.php` | the module the affiliation attaches to |
| Person record and its platform field set | `app/Models/People.php`, `app/Enums/CustomFields/PeopleField.php` | `phone_number` is already a unique identity field, `lead_source` already has a `web_form` option, consent mirrors already exist |
| Field seeding for existing workspaces | `app/Onboarding/PlatformContactFieldSet.php` | adding a case to `PeopleField` and calling `applyToEveryTenant()` is the documented way to add a contact field |
| Workspace isolation | `app/Models/Scopes/WorkspaceScope.php`, `app/Support/CurrentWorkspace.php` | reads fail closed; an unbound job or webhook must wrap its work in `within()` |
| Interactive rebind example | `app/Http/Controllers/JoinWorkspaceViaLinkController.php`, route at `routes/web.php:275` | the shape of a public, tokenised, unauthenticated page |
| Filament resource layout | `app/Filament/Resources/OpportunityResource.php`, `.../OpportunityResource/{Forms,Pages,RelationManagers}/` | the folder convention for the new resource |
| Scheduled command registration | `bootstrap/app.php` `->withSchedule(...)`, from line 268 | where the sync tick goes |

**What does not exist:** no LINE code anywhere (`grep -rli 'liff\|line_oa' app` returns nothing), no
sample request or application model, no approval queue, no Partner Centre client, and no
`lang/th/`. Two locales exist: `en` and `fr`.

**The flow this implements, from the operator's flow document.** A creator reaches the OA, taps
`APPLY SAMPLE` on the rich menu, and answers eight required questions: Full Name, Phone, Shipping
Address (TH), TikTok URL, Campaign (single select), SKU (multi select), Can follow brief (Yes/No),
Consent. Staff review it. Approve or reject follows from what is on the form. On approval the
creator is linked to the opportunity and an affiliate track with the agreed commission rate is
created or verified, then results are tracked against it.

**Assumptions you may not change without asking:**

1. The form lives in this app, not in Lark. Lark cannot open our review queue.
2. The public page must work as a LIFF page so the LINE user id is available beside the form data.
   The page still works in a plain browser with the id absent.
3. Partner Centre is called behind an interface with a fake. You will not have real credentials when
   you build this, and the tests must not touch the network.
4. One workspace per deployment of the form. The page is addressed by a workspace token, not by a
   slug, because a slug enumerates workspaces.

---

## Architecture

A new `sample_applications` table in `app/` owns the submission and its decision, written only by
two actions (`SubmitSampleApplication` on the public side, `ApproveSampleApplication` /
`RejectSampleApplication` on the staff side), reviewed in a Filament resource under the workspace
tenant. Approval creates the person and the opportunity through the existing actions, then a queued
`SyncCreatorAffiliation` job calls a `PartnerCentreClient` interface and writes a
`creator_affiliations` row keyed to the opportunity; a scheduled command refreshes attributed
results on the same row.

```
public LIFF page  ->  SubmitSampleApplication  ->  sample_applications (status submitted)
                                                          |
                                      Filament review queue (approve / reject)
                                                          |
                                    ApproveSampleApplication
                                       |-- CreatePeople          -> people
                                       |-- CreateOpportunity     -> opportunities
                                       |-- creator_affiliations  <- SyncCreatorAffiliation job
                                                          |
                              platform:sync-partner-centre-results (scheduled)
```

---

## Rules that will fail your pull request if ignored

These are not style preferences. Each one is a test or a PHPStan rule in this repo.

- No em-dashes anywhere, in code, comments, copy or commit messages. `tests/Arch/ConventionsTest.php`
  scans for the character.
- Migrations have `up()` only. Never write `down()`.
- PostgreSQL only. No driver checks, no SQLite fallback, no conditional SQL.
- Business writes go through an action in `app/Actions/<Domain>/`, named verb-first with no `Action`
  suffix, `final readonly`, one public `execute()`, authorization and tenant checks inside.
- Never write Eloquent in a controller, Filament class, job or Livewire component. PHPStan rule
  `EloquentWriteOutsideActionRule` fails it.
- Every user-facing string goes through `__()`. Two PHPStan rules fail hardcoded strings in `label()`,
  `heading()`, `title()` and guarded static properties.
- A read that more than one surface needs is a query class in `app/Queries/`, never an action.
- Retired words: `team` for the tenant, `contact` for a person, `deal` for an opportunity. Use
  workspace, person, opportunity.
- Scheduled commands are registered in `bootstrap/app.php` inside `withSchedule()`, never in
  `routes/console.php`.
- Local quality loop, in this order: `vendor/bin/pint --dirty --format agent` after each change,
  `composer test:affected` (or `php artisan test --compact <paths>` when it refuses),
  `vendor/bin/rector --dry-run` and `vendor/bin/phpstan analyse` once before pushing, then
  `composer test:arch`. Never run `composer test:pest` or the full suite locally to confirm a commit.

---

## Phase 0. Make the suite runnable

### Verified state of the environment on 2026-11-04 (read before you start)

Measured, not assumed:

| Thing | State |
|---|---|
| Local app | **cannot boot**: `.env` is missing, `node_modules` absent, no PostgreSQL on 5432, Docker not running. PHP 8.5.11, Composer 2.10.3, pnpm and node are installed, `vendor/` is present |
| Local repo | branch `platform`, HEAD `96460aa92`, clean apart from the untracked `.hermes/` that holds this plan. `origin/platform` is at the same commit |
| Branch topology | `origin/main` is an ancestor of `platform`; `platform` is 28 commits ahead of `main`. Deploy from `platform`, never from `main` |
| Host DEV050 | reachable on `console-uat.xgate.com:22050` only, from public IP `58.136.167.201`. Ports 80 and 443 for both console hostnames time out from outside, so the panel is browsed through an SSH tunnel, not a URL |
| Host runtime | every `marketing-platform-*` container up, `relaticle` healthy for 6 days, root disk 208G of 9T used |
| Deployed code | the container is at commit **2402bdf78**, two commits behind local HEAD. `f9740931d` (NOTICE) and `96460aa92` (tenant teardown) are not on the host, and `RetireTenantOwner.php`, which HEAD adds, is absent. Schema is not behind: the newest migration is on both sides and shows `Ran` |
| Host provenance | `~/marketing-platform/DEPLOYED-REVISION` used to record `revision=3f9dcbc3` on `branch=main`, a commit that exists nowhere on the remote. It now records `2402bdf78` on `platform`, established by comparing file contents, not by trusting the old value |
| Panel addresses | the CRM listens on host `127.0.0.1:3002` and redirects to `https://console-crm-uat.xgate.com/crm/`. Port 8080 is the Keycloak admin console, not this app |

### Task 0.1: environment

There is no `.env` in this checkout, so nothing runs yet.

```bash
cd /Users/ai.parry/dev/relaticle-fork
cp .env.example .env
php artisan key:generate
./vendor/bin/sail up -d
php artisan migrate --force
php artisan db:show
```

Expected: `key:generate` prints `Application key set successfully.`; `migrate` ends with
`INFO  Nothing to migrate.` on a fresh database or the list of migrations applied; `db:show` prints a
table beginning `PostgreSQL` with `Database ... Tables ...` counts.

If Docker is unavailable on your machine, point `DB_*` at any local PostgreSQL 16+ instance, create
the database, and run `php artisan migrate --force`. Nothing in this plan depends on Sail.

### Task 0.2: prove the baseline is green before you touch it

```bash
php artisan test --compact tests/Feature/CRM/CompanyModelTest.php
```

Expected: `PASS` and a summary line `Tests: N passed`. If this fails, stop. You are debugging your
environment, not the plan.

Commit: none. This task leaves no diff.

---

## Phase 1. The application record

### Task 1.1: write the failing model test

Create `tests/Feature/Sample/SampleApplicationModelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\SampleApplication;
use App\Models\Workspace;

mutates(SampleApplication::class);

test('a sample application belongs to a workspace', function () {
    $workspace = Workspace::factory()->create();
    $application = SampleApplication::factory()->create([
        'workspace_id' => $workspace->getKey(),
    ]);

    expect($application->workspace)->toBeInstanceOf(Workspace::class)
        ->and($application->workspace->getKey())->toBe($workspace->getKey());
});

test('the workspace scope hides another workspace application', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    SampleApplication::factory()->create(['workspace_id' => $theirs->getKey()]);

    $seen = app(\App\Support\CurrentWorkspace::class)->within($mine, fn () => SampleApplication::query()->count());

    expect($seen)->toBe(0);
});
```

Run it:

```bash
php artisan test --compact tests/Feature/Sample/SampleApplicationModelTest.php
```

Expected failure: `Class "App\Models\SampleApplication" not found`. That is the RED state.

### Task 1.2: the migration

Create `database/migrations/2026_11_04_130000_create_sample_applications_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignUlid('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignUlid('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('submitted');

            // One column per outward question, in the order the form asks them.
            $table->string('full_name');
            $table->string('phone', 40);
            $table->text('shipping_address');
            $table->string('tiktok_profile', 255);
            $table->string('campaign');
            $table->json('skus');
            $table->boolean('can_follow_brief');

            // Evidence. The submission exactly as it arrived, never rewritten by a later decision.
            $table->json('payload');
            $table->string('consent_copy_version', 20);
            $table->timestamp('consent_granted_at');

            // The decision.
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Present when the page ran inside LINE. Null in a plain browser.
            $table->string('line_user_id', 64)->nullable();
            $table->string('source', 30)->default('line_oa');

            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at'], 'idx_sample_applications_review_queue');
            $table->index(['workspace_id', 'line_user_id'], 'idx_sample_applications_line_user');
        });
    }
};
```

```bash
php artisan migrate --force
```

Expected: `DONE  ... create_sample_applications_table` followed by `INFO  Migrations ... done.`

### Task 1.3: the model and the factory

Create `app/Models/SampleApplication.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SampleApplicationStatus;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(WorkspaceScope::class)]
#[Fillable([
    'status',
    'full_name',
    'phone',
    'shipping_address',
    'tiktok_profile',
    'campaign',
    'skus',
    'can_follow_brief',
    'payload',
    'consent_copy_version',
    'consent_granted_at',
    'decided_at',
    'decision_note',
    'line_user_id',
    'source',
    'person_id',
    'opportunity_id',
    'reviewed_by_id',
])]
class SampleApplication extends Model
{
    use HasFactory;
    use HasUlids;
    use HasWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SampleApplicationStatus::class,
            'skus' => 'array',
            'payload' => 'array',
            'can_follow_brief' => 'boolean',
            'consent_granted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<People, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id');
    }

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
```

Create `database/factories/SampleApplicationFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SampleApplication> */
final class SampleApplicationFactory extends Factory
{
    protected $model = SampleApplication::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'status' => SampleApplicationStatus::Submitted,
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('08########'),
            'shipping_address' => fake()->address(),
            'tiktok_profile' => 'https://www.tiktok.com/@'.fake()->userName(),
            'campaign' => 'Pending catalog',
            'skus' => ['sku-default'],
            'can_follow_brief' => true,
            'payload' => [],
            'consent_copy_version' => 'v1',
            'consent_granted_at' => now(),
            'source' => 'line_oa',
        ];
    }
}
```

### Task 1.4: the status enum

Create `app/Enums/SampleApplicationStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SampleApplicationStatus: string implements HasColor, HasLabel
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Submitted => __('sample_application.status.submitted'),
            self::Approved => __('sample_application.status.approved'),
            self::Rejected => __('sample_application.status.rejected'),
        };
    }
}
```

Create `lang/en/sample_application.php` with the labels used from here on:

```php
<?php

declare(strict_types=1);

return [
    'status' => [
        'submitted' => 'Waiting for review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
    'decision' => [
        'already_decided' => 'This application has already been decided.',
        'reason_required' => 'A reason is required to reject an application.',
    ],
];
```

Run the tests from Task 1.1:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/SampleApplicationModelTest.php
```

Expected: `Tests: 2 passed`. The scope test is the important one; it fails if you forget
`#[ScopedBy(WorkspaceScope::class)]`.

Commit: `feat(sample): the application record, with the decision and the evidence in one row`

---

## Phase 2. The one contact field that does not exist yet

The form asks for a TikTok profile. `PeopleField` has email, phone, LINE ID, WeChat, KakaoTalk and
LinkedIn, but no TikTok. Add it rather than stuffing the URL into a free-text note.

### Task 2.1: failing test for the field

Create `tests/Feature/Sample/PeopleTiktokProfileFieldTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;

test('the platform contact set carries a tiktok profile field', function () {
    expect(PeopleField::TIKTOK_PROFILE->value)->toBe('tiktok_profile')
        ->and(PeopleField::TIKTOK_PROFILE->getDisplayName())->toBe('TikTok Profile')
        ->and(PeopleField::TIKTOK_PROFILE->isUniquePerEntityType())->toBeFalse();
});
```

```bash
php artisan test --compact tests/Feature/Sample/PeopleTiktokProfileFieldTest.php
```

Expected failure: `Undefined constant App\Enums\CustomFields\PeopleField::TIKTOK_PROFILE`.

### Task 2.2: add the case

Edit `app/Enums/CustomFields/PeopleField.php`:

1. Add the case beside the other social handles, next to `public const LINKEDIN = 'linkedin';`
   (around line 52):

```php
    case TIKTOK_PROFILE = 'tiktok_profile';
```

2. That file matches on `$this` in several methods. Every exhaustive `match ($this)` needs an arm or
   the app throws `UnhandledMatchError` at runtime and PHPStan fails. Add arms where required:

```php
// getDisplayName()
self::TIKTOK_PROFILE => 'TikTok Profile',

// getDescription()
self::TIKTOK_PROFILE => 'Public TikTok profile URL. The creator handle is read from here, so a bare @handle is not enough.',
```

For `getFieldType()`, `getSortOrder()`, `getOptions()`, `allowsMultipleValues()`,
`getMaxValues()`, `isUniquePerEntityType()` and the rest, run the analyser and add an arm wherever
it reports `Match expression does not handle remaining value`:

```bash
vendor/bin/phpstan analyse app/Enums/CustomFields/PeopleField.php
```

Expected before the fix: one error per unhandled match, each naming the line. Expected after: 
`[OK] No errors`.

```bash
php artisan test --compact tests/Feature/Sample/PeopleTiktokProfileFieldTest.php
```

Expected: `Tests: 1 passed`.

### Task 2.3: backfill existing workspaces

New workspaces get the field from `CreateWorkspaceCustomFields`, which reads `PeopleField::cases()`.
Workspaces that already exist need the applier, exactly as
`database/migrations/2026_10_28_100000_seed_platform_contact_fields.php` does it.

Create `database/migrations/2026_11_04_130500_add_tiktok_profile_contact_field.php`:

```php
<?php

declare(strict_types=1);

use App\Onboarding\PlatformContactFieldSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the tiktok_profile contact field into every workspace that predates the code.
 *
 * New workspaces get it from CreateWorkspaceCustomFields, which reads PeopleField::cases().
 * The applier is idempotent, so a second run inserts zero rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = PlatformContactFieldSet::applyToEveryTenant();

        Log::info('Seeded tiktok_profile into the platform contact field set.', $result);
    }
};
```

```bash
php artisan migrate --force
```

Expected: one `DONE` line for the new migration, then `INFO  Migrations ... done.`

Commit: `feat(people): a tiktok profile field in the platform contact set`

---

## Phase 3. Submitting an application

### Task 3.1: failing test

Create `tests/Feature/Sample/SubmitSampleApplicationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Sample\SubmitSampleApplication;
use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\Workspace;

mutates(SampleApplication::class);

function submissionData(): array
{
    return [
        'full_name' => 'Ploy S.',
        'phone' => '0812345678',
        'shipping_address' => '99/1 Sukhumvit, Khlong Toei, Bangkok 10110',
        'tiktok_profile' => 'https://www.tiktok.com/@ploylives',
        'campaign' => 'Pending catalog',
        'skus' => ['sku-a', 'sku-b'],
        'can_follow_brief' => 'yes',
        'consent' => 'agree',
    ];
}

test('a submission is stored with its evidence and the consent that was shown', function () {
    $workspace = Workspace::factory()->create();

    $application = app(SubmitSampleApplication::class)
        ->execute($workspace, submissionData(), 'U4af4980629');

    expect($application->status)->toBe(SampleApplicationStatus::Submitted)
        ->and($application->workspace_id)->toBe($workspace->getKey())
        ->and($application->skus)->toBe(['sku-a', 'sku-b'])
        ->and($application->can_follow_brief)->toBeTrue()
        ->and($application->line_user_id)->toBe('U4af4980629')
        ->and($application->payload['phone'])->toBe('0812345678')
        ->and($application->consent_granted_at)->not->toBeNull()
        ->and($application->consent_copy_version)->toBe('v1');
});

test('the payload keeps the raw answers even when the caller passes extra keys', function () {
    $workspace = Workspace::factory()->create();

    $application = app(SubmitSampleApplication::class)
        ->execute($workspace, [...submissionData(), 'spam_honeypot' => '']);

    expect($application->payload)->toHaveKey('spam_honeypot');
});
```

```bash
php artisan test --compact tests/Feature/Sample/SubmitSampleApplicationTest.php
```

Expected failure: `Class "App\Actions\Sample\SubmitSampleApplication" not found`.

### Task 3.2: the action

Create `app/Actions/Sample/SubmitSampleApplication.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Sample;

use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes one public submission.
 *
 * This is the shape AGENTS.md allows for a caller with no acting user: a public form has no
 * `$user`, so the canonical `abort_unless` plus `TenantFkValidator` guard does not apply. The
 * workspace comes from the page token, which is the only thing that can address a tenant here.
 */
final readonly class SubmitSampleApplication
{
    /** The version of the consent sentence the form displayed. Bump it when the sentence changes. */
    public const string CONSENT_COPY_VERSION = 'v1';

    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Workspace $workspace, array $data, ?string $lineUserId = null): SampleApplication
    {
        return $this->currentWorkspace->within($workspace, function () use ($workspace, $data, $lineUserId): SampleApplication {
            return DB::transaction(fn (): SampleApplication => SampleApplication::query()->create([
                'workspace_id' => $workspace->getKey(),
                'status' => SampleApplicationStatus::Submitted,
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'shipping_address' => $data['shipping_address'],
                'tiktok_profile' => $data['tiktok_profile'],
                'campaign' => $data['campaign'],
                'skus' => $data['skus'],
                'can_follow_brief' => $data['can_follow_brief'] === 'yes',
                'payload' => $data,
                'consent_copy_version' => self::CONSENT_COPY_VERSION,
                'consent_granted_at' => CarbonImmutable::now(),
                'line_user_id' => $lineUserId,
                'source' => 'line_oa',
            ]));
        });
    }
}
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/SubmitSampleApplicationTest.php
```

Expected: `Tests: 2 passed`.

Commit: `feat(sample): store a submission with its evidence and consent version`

---

## Phase 4. The public form

### Task 4.1: an addressable token per workspace

A public form cannot be addressed by workspace slug, because a slug enumerates tenants. Add a random
token to `workspaces`, generated for existing rows in the migration with SQL so no app code runs
there.

Create `database/migrations/2026_11_04_131000_add_apply_form_token_to_workspaces_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            // md5() is 32 hex characters, and the route constraint in routes/web.php matches that.
            $table->string('apply_form_token', 32)->nullable()->unique();
        });

        // Generated in SQL so the backfill needs no models and reaches a self-hosted install.
        // Clearing the token is how a workspace turns the public form off.
        DB::statement('UPDATE workspaces SET apply_form_token = md5(random()::text || id::text || clock_timestamp()::text) WHERE apply_form_token IS NULL');
    }
};
```

```bash
php artisan migrate --force
php artisan tinker --execute="dump(App\Models\Workspace::query()->whereNull('apply_form_token')->count());"
```

Expected: `0`.

Edit `app/Models/Workspace.php` in two places, then add the helper.

1. Add `'apply_form_token',` to the `#[Fillable([...])]` list at line 81. Without it the factory
   cannot set the token and the tests below fail with a mass-assignment error.
2. Add it to the `#[Hidden([...])]` list at line 97, beside `'invite_link_token'`, so it never leaves
   the API or a serialised payload.
3. Add the helper beside the other URL helpers:

```php
public function applyFormUrl(): ?string
{
    return $this->apply_form_token === null
        ? null
        : route('sample.apply.show', ['token' => $this->apply_form_token]);
}
```

### Task 4.2: failing test for the page

Create `tests/Feature/Sample/PublicSampleFormTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\SampleApplication;
use App\Models\Workspace;

mutates(SampleApplication::class);

test('the form renders for a valid token', function () {
    $token = str_repeat('a', 32);
    Workspace::factory()->create(['apply_form_token' => $token]);

    $this->get('/apply/'.$token)
        ->assertOk()
        ->assertSee(__('sample_application.form.title'));
});

test('an unknown token is a 404', function () {
    $this->get('/apply/'.str_repeat('b', 32))->assertNotFound();
});

test('a workspace with no token is a 404 even when its slug is guessed', function () {
    $workspace = Workspace::factory()->create(['apply_form_token' => null]);

    $this->get('/apply/'.$workspace->slug)->assertNotFound();
});

test('a submission creates the application and redirects to the receipt', function () {
    $token = str_repeat('a', 32);
    Workspace::factory()->create(['apply_form_token' => $token]);

    $this->post('/apply/'.$token, [
        'full_name' => 'Ploy S.',
        'phone' => '0812345678',
        'shipping_address' => '99/1 Sukhumvit, Khlong Toei, Bangkok 10110',
        'tiktok_profile' => 'https://www.tiktok.com/@ploylives',
        'campaign' => 'Pending catalog',
        'skus' => ['sku-a'],
        'can_follow_brief' => 'yes',
        'consent' => 'agree',
    ])->assertRedirect('/apply/'.$token.'/received');

    expect(SampleApplication::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('every one of the eight questions is required', function (string $field) {
    $token = str_repeat('a', 32);
    Workspace::factory()->create(['apply_form_token' => $token]);

    $payload = [
        'full_name' => 'Ploy S.',
        'phone' => '0812345678',
        'shipping_address' => '99/1 Sukhumvit, Khlong Toei, Bangkok 10110',
        'tiktok_profile' => 'https://www.tiktok.com/@ploylives',
        'campaign' => 'Pending catalog',
        'skus' => ['sku-a'],
        'can_follow_brief' => 'yes',
        'consent' => 'agree',
    ];
    unset($payload[$field]);

    $this->post('/apply/'.$token, $payload)->assertSessionHasErrors($field);
})->with(['full_name', 'phone', 'shipping_address', 'tiktok_profile', 'campaign', 'skus', 'can_follow_brief', 'consent']);
```

```bash
php artisan test --compact tests/Feature/Sample/PublicSampleFormTest.php
```

Expected failure: `Expected response status code [200] but received 404` (the route does not exist).

### Task 4.3: the request, the controller, the routes

Create `app/Http/Requests/Sample/SubmitSampleApplicationRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Sample;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitSampleApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            'tiktok_profile' => ['required', 'string', 'max:255', 'starts_with:https://'],
            'campaign' => ['required', 'string', 'max:120'],
            'skus' => ['required', 'array', 'min:1'],
            'skus.*' => ['string', 'max:120'],
            'can_follow_brief' => ['required', 'in:yes,no'],
            'consent' => ['required', 'in:agree'],
            'line_user_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
```

Create `app/Http/Controllers/Sample/SampleApplicationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sample;

use App\Actions\Sample\SubmitSampleApplication;
use App\Http\Requests\Sample\SubmitSampleApplicationRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final readonly class SampleApplicationController
{
    public function show(Request $request, string $token): View
    {
        return view('sample.apply', [
            'token' => $token,
            'workspace' => $this->workspace($token),
        ]);
    }

    public function store(SubmitSampleApplicationRequest $request, string $token, SubmitSampleApplication $submit): RedirectResponse
    {
        $workspace = $this->workspace($token);

        $submit->execute(
            $workspace,
            $request->safe()->except('line_user_id'),
            $request->string('line_user_id')->toString() ?: null,
        );

        return redirect()->route('sample.apply.received', ['token' => $token]);
    }

    public function received(string $token): View
    {
        $this->workspace($token);

        return view('sample.received');
    }

    private function workspace(string $token): Workspace
    {
        return Workspace::query()
            ->withoutGlobalScopes()
            ->where('apply_form_token', $token)
            ->firstOrFail();
    }
}
```

Register the routes in `routes/web.php`. They go in the public area of the file, not in the
`Route::middleware(['auth', 'verified', ...])` group that holds `/join/{token}` at line 274. A
creator applying for a sample has no account here, so an authenticated group would bounce them to
the login page.

Put them next to the invitation routes (around line 255) and copy that group's throttling, which is
`ThrottleBeforeAuthentication` rather than the stock `throttle` middleware:

```php
Route::get('/apply/{token}', [SampleApplicationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware(ThrottleBeforeAuthentication::class.':10,1,sample-apply-show')
    ->name('sample.apply.show');

Route::post('/apply/{token}', [SampleApplicationController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware(ThrottleBeforeAuthentication::class.':10,1,sample-apply-store')
    ->name('sample.apply.store');

Route::get('/apply/{token}/received', [SampleApplicationController::class, 'received'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->name('sample.apply.received');
```

The `{32}` constraint matters: without it a request to `/apply/<anything>` reaches the controller and
you lose the distinction between "wrong token" and "malformed URL". The migration generates 32 hex
characters through `md5()`, which is what the constraint matches.

Add the import at the top of the file with the other controller imports:

```php
use App\Http\Controllers\Sample\SampleApplicationController;
```

### Task 4.4: the views

Create `resources/views/sample/apply.blade.php`. The page is deliberately plain: it runs in LINE's
in-app browser on a mid-range Android phone, and the outward copy is English plus Thai on every
label, per the operator's flow document. Do not add the app's console navigation here.

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sample_application.form.title') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-neutral-900">
<main class="mx-auto max-w-lg px-5 py-8">
    <h1 class="text-xl font-semibold">{{ __('sample_application.form.title') }}</h1>
    <p class="mt-2 text-sm text-neutral-600">{{ __('sample_application.form.intro') }}</p>

    @if ($errors->any())
        <p class="mt-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            {{ __('sample_application.form.error') }}
        </p>
    @endif

    <form method="POST" action="{{ route('sample.apply.store', ['token' => $token]) }}" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="line_user_id" id="line_user_id" value="">

        @foreach (__('sample_application.form.fields') as $name => $field)
            <div>
                <label for="{{ $name }}" class="block text-sm font-medium">
                    {{ $field['en'] }} / {{ $field['th'] }}
                    @if (! empty($field['optional']))
                        <span class="font-normal text-neutral-500">({{ __('sample_application.form.optional') }})</span>
                    @endif
                </label>

                @if ($field['type'] === 'textarea')
                    <textarea id="{{ $name }}" name="{{ $name }}" rows="3" required
                              class="mt-1 block w-full rounded border-neutral-300">{{ old($name) }}</textarea>
                @elseif ($field['type'] === 'select')
                    <select id="{{ $name }}" name="{{ $name }}" required class="mt-1 block w-full rounded border-neutral-300">
                        <option value="">{{ __('sample_application.form.choose') }}</option>
                        @foreach ($field['options'] as $value => $label)
                            <option value="{{ $value }}" @selected(old($name) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @elseif ($field['type'] === 'multiselect')
                    @foreach ($field['options'] as $value => $label)
                        <label class="mt-1 flex items-center gap-2 text-sm">
                            <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}"
                                   @checked(in_array($value, old($name.'.', []), true))>
                            {{ $label }}
                        </label>
                    @endforeach
                @elseif ($field['type'] === 'checkbox')
                    <label class="mt-1 flex items-start gap-2 text-sm">
                        <input type="checkbox" id="{{ $name }}" name="{{ $name }}" value="{{ $field['value'] }}" required
                               @checked(old($name) === $field['value'])>
                        <span>{{ $field['text_en'] }}<br>{{ $field['text_th'] }}</span>
                    </label>
                @else
                    <input id="{{ $name }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ old($name) }}" required
                           class="mt-1 block w-full rounded border-neutral-300">
                @endif
            </div>
        @endforeach

        <button type="submit" class="w-full rounded bg-neutral-900 px-4 py-3 text-white">
            {{ __('sample_application.form.submit') }}
        </button>
    </form>
</main>

@if (config('services.line.liff_id'))
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script>
        // The page works without this block. It only adds the LINE user id when the page was opened
        // as a LIFF app, which is how the OA rich menu opens it.
        liff.init({liffId: @json(config('services.line.liff_id'))})
            .then(() => liff.isLoggedIn() ? liff.getProfile() : null)
            .then((profile) => {
                if (profile) {
                    document.getElementById('line_user_id').value = profile.userId;
                }
            })
            .catch(() => {});
    </script>
@endif
</body>
</html>
```

Create `resources/views/sample/received.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sample_application.received.title') }}</title>
</head>
<body class="bg-white text-neutral-900">
<main class="mx-auto max-w-lg px-5 py-10">
    <h1 class="text-xl font-semibold">{{ __('sample_application.received.title') }}</h1>
    <p class="mt-3 text-sm text-neutral-700">{{ __('sample_application.received.body') }}</p>
</main>
</body>
</html>
```

Extend `lang/en/sample_application.php`. Keep Thai beside English: the app has no `th` locale and the
form copy is locked to exactly these two languages.

```php
    'form' => [
        'title' => 'Sample Apply / ขอตัวอย่าง',
        'intro' => 'Answer all eight questions and our team will reply on business days. / ตอบครบทั้ง 8 ข้อ ทีมงานจะตอบกลับในวันทำการ',
        'error' => 'Please check the answers below. / กรุณาตรวจสอบคำตอบด้านล่าง',
        'optional' => 'optional',
        'choose' => 'Choose / เลือก',
        'submit' => 'Send application / ส่งใบสมัคร',
        'fields' => [
            'full_name' => ['en' => 'Full Name', 'th' => 'ชื่อ-นามสกุล', 'type' => 'text', 'optional' => false],
            'phone' => ['en' => 'Phone', 'th' => 'เบอร์โทร', 'type' => 'tel', 'optional' => false],
            'shipping_address' => ['en' => 'Shipping Address', 'th' => 'ที่อยู่จัดส่ง', 'type' => 'textarea', 'optional' => false],
            'tiktok_profile' => ['en' => 'TikTok URL', 'th' => 'ลิงก์ TikTok', 'type' => 'url', 'optional' => false],
            'campaign' => [
                'en' => 'Campaign', 'th' => 'แคมเปญ', 'type' => 'select', 'optional' => false,
                'options' => ['Pending catalog' => 'Pending catalog / รอรายการ', 'Other' => 'Other / อื่น ๆ'],
            ],
            'skus' => [
                'en' => 'SKU to sample', 'th' => 'SKU ที่ขอ', 'type' => 'multiselect', 'optional' => false,
                'options' => ['sku-default' => 'Sample SKU / SKU ตัวอย่าง'],
            ],
            'can_follow_brief' => [
                'en' => 'Can follow brief?', 'th' => 'ทำตามบรีฟได้ไหม', 'type' => 'select', 'optional' => false,
                'options' => ['yes' => 'Yes / ได้', 'no' => 'No / ไม่ได้'],
            ],
            'consent' => [
                'en' => 'Consent', 'th' => 'ยินยอม', 'type' => 'checkbox', 'optional' => false, 'value' => 'agree',
                'text_en' => 'I consent to content use and to being contacted about this application.',
                'text_th' => 'ข้าพเจ้ายินยอมให้ใช้เนื้อหาและให้ติดต่อกลับเกี่ยวกับใบสมัครนี้',
            ],
        ],
    ],
    'received' => [
        'title' => 'Received / ได้รับแล้ว',
        'body' => 'We have your application and our team replies on business days. / เราได้รับใบสมัครแล้ว ทีมงานจะตอบกลับในวันทำการ',
    ],
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/PublicSampleFormTest.php
```

Expected: `Tests: 12 passed` (four page tests plus eight required-field cases).

Commit: `feat(sample): the public sample form, addressed by a workspace token`

---

## Phase 5. The review queue and the decision

### Task 5.1: the policy

Create `app/Policies/SampleApplicationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceCapability;
use App\Models\SampleApplication;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceCapability;
use Illuminate\Auth\Access\HandlesAuthorization;

final readonly class SampleApplicationPolicy
{
    use ChecksWorkspaceCapability;
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsInCurrentWorkspace($user, WorkspaceCapability::RecordsView);
    }

    public function view(User $user, SampleApplication $application): bool
    {
        return $user->hasWorkspaceCapability($application->workspace_id, WorkspaceCapability::RecordsView);
    }

    public function approve(User $user, SampleApplication $application): bool
    {
        return $user->hasWorkspaceCapability($application->workspace_id, WorkspaceCapability::RecordsUpdate);
    }

    public function reject(User $user, SampleApplication $application): bool
    {
        return $user->hasWorkspaceCapability($application->workspace_id, WorkspaceCapability::RecordsUpdate);
    }
}
```

Create `tests/Feature/Sample/SampleApplicationPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\SampleApplication;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

mutates(SampleApplication::class);

test('a member of another workspace cannot view the application', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    $outsider = User::factory()->create();
    $outsider->workspaces()->attach($theirs);

    $application = SampleApplication::factory()->create(['workspace_id' => $mine->getKey()]);

    app(CurrentWorkspace::class)->within($theirs, function () use ($outsider, $application) {
        expect($outsider->can('view', $application))->toBeFalse();
    });
});
```

```bash
php artisan test --compact tests/Feature/Sample/SampleApplicationPolicyTest.php
```

Expected: `Tests: 1 passed`. Policies are discovered by convention from `app/Policies`; if the test
errors with "no policy", check that `SampleApplicationPolicy` is spelled exactly as the model.

### Task 5.2: the approve action

Create `tests/Feature/Sample/ApproveSampleApplicationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Sample\ApproveSampleApplication;
use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

mutates(SampleApplication::class);

test('approving creates the person and the opportunity and links both', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace);

    $application = SampleApplication::factory()->create(['workspace_id' => $workspace->getKey()]);

    app(CurrentWorkspace::class)->within($workspace, function () use ($user, $application) {
        $decided = app(ApproveSampleApplication::class)->execute($user, $application, 'Good profile.');

        expect($decided->status)->toBe(SampleApplicationStatus::Approved)
            ->and($decided->person_id)->not->toBeNull()
            ->and($decided->opportunity_id)->not->toBeNull()
            ->and($decided->reviewed_by_id)->toBe($user->getKey())
            ->and($decided->decided_at)->not->toBeNull();
    });
});

test('a decided application cannot be approved twice', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace);

    $application = SampleApplication::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'status' => SampleApplicationStatus::Rejected,
    ]);

    app(CurrentWorkspace::class)->within($workspace, function () use ($user, $application) {
        expect(fn () => app(ApproveSampleApplication::class)->execute($user, $application))
            ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    });
});
```

```bash
php artisan test --compact tests/Feature/Sample/ApproveSampleApplicationTest.php
```

Expected failure: `Class "App\Actions\Sample\ApproveSampleApplication" not found`.

Create `app/Actions/Sample/ApproveSampleApplication.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Sample;

use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\People\CreatePeople;
use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns an approved application into a person and an opportunity.
 *
 * The decision itself is a write on the application, so it lives here rather than in the Filament
 * page: the page is one caller and an API or MCP caller would be the next.
 */
final readonly class ApproveSampleApplication
{
    public function __construct(
        private CreatePeople $createPeople,
        private CreateOpportunity $createOpportunity,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    public function execute(User $user, SampleApplication $application, ?string $note = null): SampleApplication
    {
        abort_unless($user->can('approve', $application), 403);
        abort_unless(
            $application->status === SampleApplicationStatus::Submitted,
            409,
            __('sample_application.decision.already_decided'),
        );

        return $this->currentWorkspace->within($application->workspace_id, function () use ($user, $application, $note): SampleApplication {
            return DB::transaction(function () use ($user, $application, $note): SampleApplication {
                $person = $this->createPeople->execute($user, [
                    'name' => $application->full_name,
                    'custom_fields' => [
                        'phone_number' => $application->phone,
                        'tiktok_profile' => $application->tiktok_profile,
                        'lead_source' => 'web_form',
                    ],
                ]);

                $opportunity = $this->createOpportunity->execute($user, [
                    'name' => $application->tiktok_profile,
                    'contact_id' => $person->getKey(),
                ]);

                $application->forceFill([
                    'status' => SampleApplicationStatus::Approved,
                    'person_id' => $person->getKey(),
                    'opportunity_id' => $opportunity->getKey(),
                    'reviewed_by_id' => $user->getKey(),
                    'decided_at' => CarbonImmutable::now(),
                    'decision_note' => $note,
                ])->save();

                return $application->refresh();
            });
        });
    }
}
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/ApproveSampleApplicationTest.php
```

Expected: `Tests: 2 passed`.

If the first test fails on `lead_source`, check the option names in
`app/Enums/CustomFields/PeopleField.php` `LEAD_SOURCE_OPTIONS` and use one of those keys exactly.

Commit: `feat(sample): approving creates the person and the opportunity`

### Task 5.3: the reject action

Create `tests/Feature/Sample/RejectSampleApplicationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Sample\RejectSampleApplication;
use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

mutates(SampleApplication::class);

test('rejecting records the reason and creates nothing', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace);

    $application = SampleApplication::factory()->create(['workspace_id' => $workspace->getKey()]);

    app(CurrentWorkspace::class)->within($workspace, function () use ($user, $application) {
        $decided = app(RejectSampleApplication::class)->execute($user, $application, 'No profile activity.');

        expect($decided->status)->toBe(SampleApplicationStatus::Rejected)
            ->and($decided->decision_note)->toBe('No profile activity.')
            ->and($decided->person_id)->toBeNull()
            ->and($decided->opportunity_id)->toBeNull();
    });
});

test('a rejection without a reason is refused', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace);

    $application = SampleApplication::factory()->create(['workspace_id' => $workspace->getKey()]);

    app(CurrentWorkspace::class)->within($workspace, function () use ($user, $application) {
        expect(fn () => app(RejectSampleApplication::class)->execute($user, $application, '  '))
            ->toThrow(InvalidArgumentException::class);
    });
});
```

```bash
php artisan test --compact tests/Feature/Sample/RejectSampleApplicationTest.php
```

Expected failure: class not found.

Create `app/Actions/Sample/RejectSampleApplication.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Sample;

use App\Enums\SampleApplicationStatus;
use App\Models\SampleApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class RejectSampleApplication
{
    public function execute(User $user, SampleApplication $application, string $reason): SampleApplication
    {
        abort_unless($user->can('reject', $application), 403);
        abort_unless(
            $application->status === SampleApplicationStatus::Submitted,
            409,
            __('sample_application.decision.already_decided'),
        );

        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('sample_application.decision.reason_required'));
        }

        $application->forceFill([
            'status' => SampleApplicationStatus::Rejected,
            'reviewed_by_id' => $user->getKey(),
            'decided_at' => CarbonImmutable::now(),
            'decision_note' => trim($reason),
        ])->save();

        return $application->refresh();
    }
}
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/RejectSampleApplicationTest.php
```

Expected: `Tests: 2 passed`.

Commit: `feat(sample): rejecting records a reason and creates nothing`

### Task 5.4: the Filament resource

Create the folder and files, mirroring `app/Filament/Resources/OpportunityResource/`:

- `app/Filament/Resources/SampleApplicationResource.php`
- `app/Filament/Resources/SampleApplicationResource/Pages/ListSampleApplications.php`
- `app/Filament/Resources/SampleApplicationResource/Pages/ViewSampleApplication.php`

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SampleApplicationResource\Pages\ListSampleApplications;
use App\Filament\Resources\SampleApplicationResource\Pages\ViewSampleApplication;
use App\Models\SampleApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SampleApplicationResource extends Resource
{
    protected static ?string $model = SampleApplication::class;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('sample_application.nav.label');
    }

    public static function getModelLabel(): string
    {
        return __('sample_application.nav.label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('full_name')->searchable()->sortable(),
                TextColumn::make('tiktok_profile')->searchable()->limit(40),
                TextColumn::make('campaign'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(\App\Enums\SampleApplicationStatus::cases())
                        ->mapWithKeys(fn (\App\Enums\SampleApplicationStatus $case): array => [$case->value => $case->getLabel()])
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSampleApplications::route('/'),
            'view' => ViewSampleApplication::route('/{record}'),
        ];
    }
}
```

`ListSampleApplications` follows `app/Filament/Resources/OpportunityResource/Pages/ListOpportunities.php`:
open that file and copy its shape, changing the resource class and the title.

`ViewSampleApplication` shows the submission as evidence and carries the two actions:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\SampleApplicationResource\Pages;

use App\Actions\Sample\ApproveSampleApplication;
use App\Actions\Sample\RejectSampleApplication;
use App\Enums\SampleApplicationStatus;
use App\Filament\Resources\SampleApplicationResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

final class ViewSampleApplication extends ViewRecord
{
    protected static string $resource = SampleApplicationResource::class;

    public function form(Schema $schema): Schema
    {
        // Read only: the answers are evidence, and editing them after a decision would destroy it.
        return $schema->components([])->disabled();
    }

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('sample_application.action.approve'))
                ->color('success')
                ->visible(fn (): bool => $this->record->status === SampleApplicationStatus::Submitted
                    && auth()->user()->can('approve', $this->record))
                ->requiresConfirmation()
                ->form([
                    Textarea::make('note')->label(__('sample_application.action.note'))->rows(2),
                ])
                ->action(function (array $data): void {
                    app(ApproveSampleApplication::class)->execute(
                        auth()->user(),
                        $this->record,
                        $data['note'] ?? null,
                    );

                    Notification::make()->success()->title(__('sample_application.action.approved'))->send();
                    $this->redirect(SampleApplicationResource::getUrl('view', ['record' => $this->record]));
                }),
            Action::make('reject')
                ->label(__('sample_application.action.reject'))
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === SampleApplicationStatus::Submitted
                    && auth()->user()->can('reject', $this->record))
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')
                        ->label(__('sample_application.action.reason'))
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    app(RejectSampleApplication::class)->execute(
                        auth()->user(),
                        $this->record,
                        $data['reason'],
                    );

                    Notification::make()->success()->title(__('sample_application.action.rejected'))->send();
                    $this->redirect(SampleApplicationResource::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
```

Add to `lang/en/sample_application.php`:

```php
    'nav' => [
        'label' => 'Sample applications',
    ],
    'action' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'note' => 'Note for the record',
        'reason' => 'Why this is rejected',
        'approved' => 'Application approved',
        'rejected' => 'Application rejected',
    ],
```

Create `tests/Feature/Sample/SampleApplicationResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\SampleApplicationResource;
use App\Models\SampleApplication;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;

mutates(SampleApplication::class);

test('a reviewer sees a submitted application in the queue', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace);
    $application = SampleApplication::factory()->create(['workspace_id' => $workspace->getKey()]);

    Filament::setTenant($workspace);
    $this->actingAs($user);

    livewire(\App\Filament\Resources\SampleApplicationResource\Pages\ListSampleApplications::class)
        ->assertCanSeeTableRecords([$application]);
});
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/SampleApplicationResourceTest.php
```

Expected: `Tests: 1 passed`. If Livewire reports a missing panel or tenant, mirror the setup used in
`tests/Feature/CRM/` tests that mount a Filament page, and keep `Filament::setTenant($workspace)`.

Commit: `feat(sample): a review queue with approve and reject in the workspace panel`

---

## Phase 6. Partner Centre

Nobody will hand you working Partner Centre credentials during this build. So the network call sits
behind an interface with a fake, the fake is what the tests use, and the real client is the only
thing that needs credentials later.

### Task 6.1: the contract, the data, the fake

Create `app/Data/PartnerCentre/CreatorAffiliationData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\PartnerCentre;

use App\Enums\PartnerCreatorStatus;

final readonly class CreatorAffiliationData
{
    public function __construct(
        public string $partnerCreatorId,
        public string $affiliateTrackId,
        public float $commissionRate,
        public PartnerCreatorStatus $status,
    ) {}
}
```

Create `app/Enums/PartnerCreatorStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PartnerCreatorStatus: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Pending => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => __('sample_application.partner.status.unknown'),
            self::Pending => __('sample_application.partner.status.pending'),
            self::Active => __('sample_application.partner.status.active'),
            self::Suspended => __('sample_application.partner.status.suspended'),
        };
    }
}
```

Create `app/Contracts/PartnerCentre/PartnerCentreClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Contracts\PartnerCentre;

use App\Data\PartnerCentre\CreatorAffiliationData;
use App\Data\PartnerCentre\ResultTotals;
use Carbon\CarbonImmutable;

interface PartnerCentreClient
{
    /**
     * Look a creator up by TikTok profile URL and return their affiliation, creating the track when
     * the platform has none yet. Throws PartnerCentreUnavailable when the platform cannot answer.
     */
    public function affiliationFor(string $tiktokProfile, float $commissionRate): CreatorAffiliationData;

    /** Attributed results for one track over a closed period. */
    public function results(string $affiliateTrackId, CarbonImmutable $from, CarbonImmutable $to): ResultTotals;
}
```

Create `app/Data/PartnerCentre/ResultTotals.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data\PartnerCentre;

final readonly class ResultTotals
{
    public function __construct(
        public int $orderCount,
        public float $gmv,
        public float $commission,
    ) {}
}
```

Create `app/Services/PartnerCentre/FakePartnerCentreClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\PartnerCentre;

use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Data\PartnerCentre\CreatorAffiliationData;
use App\Data\PartnerCentre\ResultTotals;
use App\Enums\PartnerCreatorStatus;
use Carbon\CarbonImmutable;

/**
 * Deterministic stand-in for the platform. Tests configure the answers with `willReturn`.
 */
final class FakePartnerCentreClient implements PartnerCentreClient
{
    public ?CreatorAffiliationData $affiliation = null;

    public ?ResultTotals $totals = null;

    public ?string $error = null;

    public function affiliationFor(string $tiktokProfile, float $commissionRate): CreatorAffiliationData
    {
        if ($this->error !== null) {
            throw new PartnerCentreUnavailable($this->error);
        }

        return $this->affiliation ??= new CreatorAffiliationData(
            partnerCreatorId: 'pcr_'.md5($tiktokProfile),
            affiliateTrackId: 'aff_'.md5($tiktokProfile),
            commissionRate: $commissionRate,
            status: PartnerCreatorStatus::Pending,
        );
    }

    public function results(string $affiliateTrackId, CarbonImmutable $from, CarbonImmutable $to): ResultTotals
    {
        if ($this->error !== null) {
            throw new PartnerCentreUnavailable($this->error);
        }

        return $this->totals ??= new ResultTotals(orderCount: 0, gmv: 0.0, commission: 0.0);
    }
}
```

Create `app/Services/PartnerCentre/PartnerCentreUnavailable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\PartnerCentre;

use RuntimeException;

final class PartnerCentreUnavailable extends RuntimeException {}
```

Create `app/Services/PartnerCentre/HttpPartnerCentreClient.php`. This is the only file that knows the
platform's wire format. Fill the two request bodies from the platform's own API documentation once
credentials exist; until then it must throw rather than return invented data:

```php
<?php

declare(strict_types=1);

namespace App\Services\PartnerCentre;

use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Data\PartnerCentre\CreatorAffiliationData;
use App\Data\PartnerCentre\ResultTotals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final readonly class HttpPartnerCentreClient implements PartnerCentreClient
{
    public function __construct(
        private string $baseUrl,
        private string $appKey,
        private string $appSecret,
    ) {}

    public function affiliationFor(string $tiktokProfile, float $commissionRate): CreatorAffiliationData
    {
        $response = Http::withHeaders($this->signature())
            ->timeout(15)
            ->post($this->baseUrl.'/affiliate/creator/affiliation', [
                'creator_profile' => $tiktokProfile,
                'commission_rate' => $commissionRate,
            ]);

        if ($response->failed()) {
            throw new PartnerCentreUnavailable('affiliation request failed: '.$response->status());
        }

        /** @var array{partner_creator_id: string, affiliate_track_id: string, commission_rate: float, status: string} $body */
        $body = $response->json();

        return new CreatorAffiliationData(
            partnerCreatorId: $body['partner_creator_id'],
            affiliateTrackId: $body['affiliate_track_id'],
            commissionRate: (float) $body['commission_rate'],
            status: \App\Enums\PartnerCreatorStatus::tryFrom($body['status']) ?? \App\Enums\PartnerCreatorStatus::Unknown,
        );
    }

    public function results(string $affiliateTrackId, CarbonImmutable $from, CarbonImmutable $to): ResultTotals
    {
        $response = Http::withHeaders($this->signature())
            ->timeout(15)
            ->get($this->baseUrl.'/affiliate/track/results', [
                'track_id' => $affiliateTrackId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);

        if ($response->failed()) {
            throw new PartnerCentreUnavailable('results request failed: '.$response->status());
        }

        /** @var array{order_count: int, gmv: float, commission: float} $body */
        $body = $response->json();

        return new ResultTotals(
            orderCount: (int) $body['order_count'],
            gmv: (float) $body['gmv'],
            commission: (float) $body['commission'],
        );
    }

    /** @return array<string, string> */
    private function signature(): array
    {
        return [
            'x-app-key' => $this->appKey,
            'x-app-secret' => $this->appSecret,
        ];
    }
}
```

Two things to be honest about in review: the endpoint paths and the signature scheme are placeholders
until the platform's documentation is in hand, and `x-app-secret` in a header assumes a static
credential. Replace both when you have the real spec, and keep the interface and the fake unchanged.

### Task 6.2: the affiliation table

Create `database/migrations/2026_11_04_140000_create_creator_affiliations_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_affiliations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('opportunity_id')->unique()->constrained('opportunities')->cascadeOnDelete();

            $table->string('partner_creator_id', 100)->nullable();
            $table->string('affiliate_track_id', 100)->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->string('status', 20)->default('unknown');

            $table->unsignedInteger('attributed_order_count')->default(0);
            $table->decimal('attributed_gmv', 12, 2)->default(0);
            $table->decimal('commission_earned', 12, 2)->default(0);

            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'idx_creator_affiliations_status');
        });
    }
};
```

Create `app/Models/CreatorAffiliation.php` following `SampleApplication`: `ulid` key, `HasWorkspace`,
`#[ScopedBy(WorkspaceScope::class)]`, casts for `status` (`PartnerCreatorStatus::class`) and
`last_synced_at` (`immutable_datetime`), and a `opportunity()` belongsTo.

Create `database/factories/CreatorAffiliationFactory.php` mirroring `SampleApplicationFactory`.

Create `tests/Feature/Sample/CreatorAffiliationModelTest.php` asserting the workspace relation and
that `status` casts to `PartnerCreatorStatus`.

```bash
php artisan migrate --force
php artisan test --compact tests/Feature/Sample/CreatorAffiliationModelTest.php
```

Expected: migration `DONE`, then `Tests: 1 passed`.

### Task 6.3: the sync action

Create `tests/Feature/Sample/SyncCreatorAffiliationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\PartnerCentre\SyncCreatorAffiliation;
use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Data\PartnerCentre\CreatorAffiliationData;
use App\Enums\PartnerCreatorStatus;
use App\Models\CreatorAffiliation;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\PartnerCentre\FakePartnerCentreClient;

mutates(CreatorAffiliation::class);

test('the affiliation from the platform is written onto the opportunity', function () {
    $workspace = Workspace::factory()->create();
    $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->getKey()]);

    $fake = new FakePartnerCentreClient;
    $fake->affiliation = new CreatorAffiliationData(
        partnerCreatorId: 'pcr_1',
        affiliateTrackId: 'aff_1',
        commissionRate: 12.5,
        status: PartnerCreatorStatus::Active,
    );
    app()->instance(PartnerCentreClient::class, $fake);

    $affiliation = app(SyncCreatorAffiliation::class)
        ->execute($workspace, $opportunity, 'https://www.tiktok.com/@ploylives', 12.5);

    expect($affiliation->affiliate_track_id)->toBe('aff_1')
        ->and($affiliation->status)->toBe(PartnerCreatorStatus::Active)
        ->and((float) $affiliation->commission_rate)->toBe(12.5)
        ->and($affiliation->last_synced_at)->not->toBeNull()
        ->and($affiliation->sync_error)->toBeNull();
});

test('a platform failure is recorded and does not throw', function () {
    $workspace = Workspace::factory()->create();
    $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->getKey()]);

    $fake = new FakePartnerCentreClient;
    $fake->error = 'timeout';
    app()->instance(PartnerCentreClient::class, $fake);

    $affiliation = app(SyncCreatorAffiliation::class)
        ->execute($workspace, $opportunity, 'https://www.tiktok.com/@ploylives', 10.0);

    expect($affiliation->sync_error)->toBe('timeout')
        ->and($affiliation->affiliate_track_id)->toBeNull();
});
```

Create `app/Actions/PartnerCentre/SyncCreatorAffiliation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\PartnerCentre;

use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Models\CreatorAffiliation;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\PartnerCentre\PartnerCentreUnavailable;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * Fetches the creator's affiliate track from Partner Centre and stores it against the opportunity.
 *
 * No acting user: this runs from a queued job and from a scheduled command, which is the shape
 * AGENTS.md describes for a caller without one. A failure is stored on the row, not thrown, because
 * a platform outage must not lose the approval that already happened.
 */
final readonly class SyncCreatorAffiliation
{
    public function __construct(
        private PartnerCentreClient $client,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    public function execute(Workspace $workspace, Opportunity $opportunity, string $tiktokProfile, float $commissionRate): CreatorAffiliation
    {
        return $this->currentWorkspace->within($workspace, function () use ($workspace, $opportunity, $tiktokProfile, $commissionRate): CreatorAffiliation {
            $affiliation = CreatorAffiliation::query()->firstOrNew([
                'opportunity_id' => $opportunity->getKey(),
            ]);

            $affiliation->workspace_id = $workspace->getKey();

            try {
                $data = $this->client->affiliationFor($tiktokProfile, $commissionRate);

                $affiliation->fill([
                    'partner_creator_id' => $data->partnerCreatorId,
                    'affiliate_track_id' => $data->affiliateTrackId,
                    'commission_rate' => $data->commissionRate,
                    'status' => $data->status,
                    'sync_error' => null,
                ]);
            } catch (PartnerCentreUnavailable $exception) {
                $affiliation->fill(['sync_error' => $exception->getMessage()]);
            }

            $affiliation->last_synced_at = CarbonImmutable::now();
            $affiliation->save();

            return $affiliation->refresh();
        });
    }
}
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/SyncCreatorAffiliationTest.php
```

Expected: `Tests: 2 passed`.

Commit: `feat(partner-centre): store the affiliate track on the opportunity`

### Task 6.4: the job, and the workspace it needs

A queued job starts with no workspace bound, and `WorkspaceScope` fails closed, so the job must bind
the workspace itself.

Create `app/Jobs/PartnerCentre/SyncCreatorAffiliationJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\PartnerCentre;

use App\Actions\PartnerCentre\SyncCreatorAffiliation;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncCreatorAffiliationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $workspaceId,
        public string $opportunityId,
        public string $tiktokProfile,
        public float $commissionRate,
    ) {}

    public function handle(SyncCreatorAffiliation $sync): void
    {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($this->workspaceId);
        $opportunity = Opportunity::query()->withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->find($this->opportunityId);

        if (! $workspace instanceof Workspace || ! $opportunity instanceof Opportunity) {
            return;
        }

        $sync->execute($workspace, $opportunity, $this->tiktokProfile, $this->commissionRate);
    }
}
```

Test it by calling `handle()` directly inside a test that asserts the row lands despite no ambient
workspace: create the opportunity in workspace A, bind nothing, dispatch through
`SyncCreatorAffiliationJob::dispatchSync(...)`, then assert `CreatorAffiliation::query()->withoutGlobalScopes()->count()` is 1.
Put that in `tests/Feature/Sample/SyncCreatorAffiliationJobTest.php`.

```bash
php artisan test --compact tests/Feature/Sample/SyncCreatorAffiliationJobTest.php
```

Expected: `Tests: 1 passed`.

### Task 6.5: dispatch on approval

Add to `ApproveSampleApplication`, after the application is saved and still inside the transaction
boundary (dispatch after commit so a rolled-back approval queues nothing):

```php
use App\Jobs\PartnerCentre\SyncCreatorAffiliationJob;

// ... after $application->refresh(), before return:
SyncCreatorAffiliationJob::dispatch(
    $application->workspace_id,
    $opportunity->getKey(),
    $application->tiktok_profile,
    0.0,
)->afterCommit();
```

The `0.0` is deliberate: the commission rate is agreed with the creator, and nothing on the form asks
for it. Until a field or a settings screen carries it, the platform's own rate is authoritative.
Record that in the commit message so the next reader does not think it is a bug.

Extend `tests/Feature/Sample/ApproveSampleApplicationTest.php`:

```php
test('approving queues the affiliation sync', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->workspaces()->attach($workspace);
    $application = SampleApplication::factory()->create(['workspace_id' => $workspace->getKey()]);

    app(CurrentWorkspace::class)->within($workspace, function () use ($user, $application) {
        app(ApproveSampleApplication::class)->execute($user, $application);
    });

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\PartnerCentre\SyncCreatorAffiliationJob::class);
});
```

```bash
php artisan test --compact tests/Feature/Sample/ApproveSampleApplicationTest.php
```

Expected: `Tests: 3 passed`.

Commit: `feat(sample): an approval queues the affiliation sync`

---

## Phase 7. Tracking results

### Task 7.1: the results action

Create `tests/Feature/Sample/SyncAffiliationResultsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\PartnerCentre\SyncAffiliationResults;
use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Data\PartnerCentre\ResultTotals;
use App\Models\CreatorAffiliation;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\PartnerCentre\FakePartnerCentreClient;
use Carbon\CarbonImmutable;

mutates(CreatorAffiliation::class);

test('the totals are written onto the affiliation', function () {
    $workspace = Workspace::factory()->create();
    $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->getKey()]);
    $affiliation = CreatorAffiliation::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'opportunity_id' => $opportunity->getKey(),
        'affiliate_track_id' => 'aff_1',
    ]);

    $fake = new FakePartnerCentreClient;
    $fake->totals = new ResultTotals(orderCount: 7, gmv: 1234.50, commission: 98.76);
    app()->instance(PartnerCentreClient::class, $fake);

    $updated = app(SyncAffiliationResults::class)->execute($affiliation);

    expect($updated->attributed_order_count)->toBe(7)
        ->and((float) $updated->attributed_gmv)->toBe(1234.50)
        ->and((float) $updated->commission_earned)->toBe(98.76)
        ->and($updated->last_synced_at)->not->toBeNull();
});

test('an affiliation with no track id is skipped without an error', function () {
    $workspace = Workspace::factory()->create();
    $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->getKey()]);
    $affiliation = CreatorAffiliation::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'opportunity_id' => $opportunity->getKey(),
        'affiliate_track_id' => null,
    ]);

    $unchanged = app(SyncAffiliationResults::class)->execute($affiliation);

    expect($unchanged->attributed_order_count)->toBe(0)
        ->and($unchanged->sync_error)->toBeNull();
});
```

Create `app/Actions/PartnerCentre/SyncAffiliationResults.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\PartnerCentre;

use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Models\CreatorAffiliation;
use App\Services\PartnerCentre\PartnerCentreUnavailable;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;

final readonly class SyncAffiliationResults
{
    public function __construct(
        private PartnerCentreClient $client,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    public function execute(CreatorAffiliation $affiliation): CreatorAffiliation
    {
        if (blank($affiliation->affiliate_track_id)) {
            return $affiliation;
        }

        return $this->currentWorkspace->within($affiliation->workspace_id, function () use ($affiliation): CreatorAffiliation {
            try {
                $totals = $this->client->results(
                    $affiliation->affiliate_track_id,
                    CarbonImmutable::now()->subDays(30)->startOfDay(),
                    CarbonImmutable::now()->endOfDay(),
                );

                $affiliation->fill([
                    'attributed_order_count' => $totals->orderCount,
                    'attributed_gmv' => $totals->gmv,
                    'commission_earned' => $totals->commission,
                    'sync_error' => null,
                ]);
            } catch (PartnerCentreUnavailable $exception) {
                $affiliation->fill(['sync_error' => $exception->getMessage()]);
            }

            $affiliation->last_synced_at = CarbonImmutable::now();
            $affiliation->save();

            return $affiliation->refresh();
        });
    }
}
```

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Sample/SyncAffiliationResultsTest.php
```

Expected: `Tests: 2 passed`.

### Task 7.2: the scheduled command

Create `app/Console/Commands/SyncPartnerCentreResultsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PartnerCentre\SyncAffiliationResults;
use App\Models\CreatorAffiliation;
use Illuminate\Console\Command;

final class SyncPartnerCentreResultsCommand extends Command
{
    protected $signature = 'platform:sync-partner-centre-results {--limit=200}';

    protected $description = 'Refresh attributed results for every creator affiliation with a track id';

    public function handle(SyncAffiliationResults $sync): int
    {
        $affiliations = CreatorAffiliation::query()
            ->withoutGlobalScopes()
            ->whereNotNull('affiliate_track_id')
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($affiliations as $affiliation) {
            $sync->execute($affiliation);
        }

        $this->info('Synced '.$affiliations->count().' affiliations.');

        return self::SUCCESS;
    }
}
```

Register it in `bootstrap/app.php`, inside the existing `->withSchedule(function (Schedule $schedule): void { ... })` block, beside the lifecycle reconcile entries:

```php
        // Attributed results, refreshed on the same rhythm as the other reconciliation ticks.
        $schedule->command('platform:sync-partner-centre-results')
            ->everySixHours()
            ->withoutOverlapping()
            ->onOneServer();
```

Create `tests/Feature/Sample/SyncPartnerCentreResultsCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Contracts\PartnerCentre\PartnerCentreClient;
use App\Data\PartnerCentre\ResultTotals;
use App\Models\CreatorAffiliation;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\PartnerCentre\FakePartnerCentreClient;

mutates(CreatorAffiliation::class);

test('the command refreshes cited affiliations and reports the count', function () {
    $workspace = Workspace::factory()->create();
    $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->getKey()]);
    CreatorAffiliation::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'opportunity_id' => $opportunity->getKey(),
        'affiliate_track_id' => 'aff_1',
    ]);

    $fake = new FakePartnerCentreClient;
    $fake->totals = new ResultTotals(orderCount: 3, gmv: 100.0, commission: 8.0);
    app()->instance(PartnerCentreClient::class, $fake);

    $this->artisan('platform:sync-partner-centre-results')
        ->expectsOutput('Synced 1 affiliations.')
        ->assertSuccessful();
});
```

```bash
php artisan test --compact tests/Feature/Sample/SyncPartnerCentreResultsCommandTest.php
```

Expected: `Tests: 1 passed`.

Commit: `feat(partner-centre): a scheduled refresh for attributed results`

### Task 7.3: bind the client

In `app/Providers/AppServiceProvider.php`, inside `register()`:

```php
$this->app->bind(\App\Contracts\PartnerCentre\PartnerCentreClient::class, function (): \App\Contracts\PartnerCentre\PartnerCentreClient {
    if (config('services.partner_centre.driver') === 'fake') {
        return new \App\Services\PartnerCentre\FakePartnerCentreClient;
    }

    return new \App\Services\PartnerCentre\HttpPartnerCentreClient(
        baseUrl: (string) config('services.partner_centre.base_url'),
        appKey: (string) config('services.partner_centre.app_key'),
        appSecret: (string) config('services.partner_centre.app_secret'),
    );
});
```

In `config/services.php`, beside the existing entries:

```php
    'partner_centre' => [
        'driver' => env('PARTNER_CENTRE_DRIVER', 'fake'),
        'base_url' => env('PARTNER_CENTRE_BASE_URL'),
        'app_key' => env('PARTNER_CENTRE_APP_KEY'),
        'app_secret' => env('PARTNER_CENTRE_APP_SECRET'),
    ],

    'line' => [
        'liff_id' => env('LINE_LIFF_ID'),
    ],
```

In `.env.example`, under a new comment block:

```
PARTNER_CENTRE_DRIVER=fake
PARTNER_CENTRE_BASE_URL=
PARTNER_CENTRE_APP_KEY=
PARTNER_CENTRE_APP_SECRET=
LINE_LIFF_ID=
```

```bash
php artisan config:clear
php artisan test --compact tests/Feature/Sample
```

Expected: every test in the folder passes, `Tests: N passed`, no failures.

Commit: `feat(partner-centre): bind the client behind a driver switch`

---

## Phase 8. Wiring the OA (no code, but not optional)

The form is useless if the rich menu still points at Lark. These steps are done in consoles, and no
step here can be verified by a test.

1. **Deploy, then read the token.** After Phase 4 is on the server:
   `php artisan tinker --execute="echo App\Models\Workspace::query()->first()->applyFormUrl();"`
   Expected: one `https://<host>/apply/<40 hex chars>` line.
2. **Register the LIFF app** in the LINE Developers Console, under the same Provider as the OA, on the
   LINE Login channel: Endpoint URL is that `/apply/{token}` URL, size `Full`, scopes `profile` and
   `openid`. Copy the LIFF id into `LINE_LIFF_ID`. This is the step that makes `line_user_id` present.
3. **Point the rich menu button** `APPLY SAMPLE / ขอตัวอย่าง` at
   `https://liff.line.me/{liffId}` instead of the Lark share URL. The rich menu image stays as it is.
4. **Acceptance walk, on a phone that has not applied before:** tap the button, answer all eight
   questions, submit, and confirm the receipt page. Then in the panel open `Sample applications`, find
   the row, confirm `line_user_id` is populated, approve it, and confirm a person and an opportunity
   were created and that `creator_affiliations` has a row with a `sync_error` when the Partner Centre
   driver is `fake`.
5. **Retire the Lark form link** from the FAQ copy only after step 4 passes. Two live intake paths
   means two queues and one of them is invisible.

---

## Tests and validation summary

| Phase | Command | Expected |
|---|---|---|
| 0 | `php artisan test --compact tests/Feature/CRM/CompanyModelTest.php` | passes before any change |
| 1 | `php artisan test --compact tests/Feature/Sample/SampleApplicationModelTest.php` | `Tests: 2 passed` |
| 2 | `php artisan test --compact tests/Feature/Sample/PeopleTiktokProfileFieldTest.php` | `Tests: 1 passed` |
| 3 | `php artisan test --compact tests/Feature/Sample/SubmitSampleApplicationTest.php` | `Tests: 2 passed` |
| 4 | `php artisan test --compact tests/Feature/Sample/PublicSampleFormTest.php` | `Tests: 12 passed` |
| 5 | `php artisan test --compact tests/Feature/Sample/SampleApplicationResourceTest.php tests/Feature/Sample/ApproveSampleApplicationTest.php` | all pass |
| 6 | `php artisan test --compact tests/Feature/Sample/SyncCreatorAffiliationTest.php` | `Tests: 2 passed` |
| 7 | `php artisan test --compact tests/Feature/Sample` | all pass |

Before pushing, once:

```bash
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:arch
```

Expected: rector reports no suggested changes, PHPStan reports `[OK] No errors`, `composer test:arch`
passes in about 30 seconds. `composer test:arch` is the one that catches a stray em-dash, a `team`
identifier, a `Deal` label, or an action with a second public method.

---

## Risks, tradeoffs, and open questions

**The Partner Centre wire format is unverified.** The interface, the data objects and the fake are
solid, and every test runs against the fake. The two endpoint paths and the header signature in
`HttpPartnerCentreClient` are placeholders written from the shape of the capability, not from the
platform's documentation, because that documentation was not available while writing this plan.
Treat that class as unfinished until someone with credentials replaces those two calls. Do not let a
green suite convince you the integration works.

**The commission rate is not on the form.** The flow document says the track is generated "with the
agreed commission rate", but none of the eight questions asks for it. Today the sync passes `0.0` and
the platform's own rate wins. If staff negotiate a rate before the creator applies, this needs a ninth
field or a settings screen, and that is a product decision, not a code one.

**Two intake paths are worse than one.** Until step 5 of Phase 8, Lark and this form both accept
applications. Pick a cutover date.

**Approval creates records; it does not merge them.** If the same creator applied twice, or the
person already exists from a DM, `CreatePeople` makes a second person. The repo already has an upsert
match ladder (`app/Concerns/ResolvesUpsertMatch.php`) with `phone_number` as a unique field, and
reusing it is the right follow-up. It is deliberately out of scope here because it changes the shape
of the approve action from "create" to "match or create", and that deserves its own review.

**Consent is recorded, not mirrored.** The application stores `consent_granted_at` and a copy
version, which is the evidence. It does not write `marketing_optin` on the person, because agreeing
to be contacted about one application is not the same permission as marketing consent. If the
operator wants the mirror, that is a separate decision with a legal owner.

**The LINE id is stored, not joined.** `sample_applications.line_user_id` holds the Messaging API
user id when the page ran as a LIFF app. Turning that into a durable person-to-follower join is the
bridge described in `marketing-console/design/MCN-AFFILIATE-SALES-FLOW.md` section 11.1, which needs
a LIFF app under the same Provider and a decision about first touch. Out of scope here.

**Requesting the sample SKU catalog.** `skus` and `campaign` options are placeholders
(`sku-default`, `Pending catalog`) because the operator has not filled the ready-to-sell catalog. They
must come from somewhere editable before launch. A settings screen is the honest answer and it is not
in this plan.

**Rate limiting is coarse.** `ThrottleBeforeAuthentication` at 10 requests per minute, bucketed per
route. A shared mobile carrier NAT in Thailand can put many real creators behind one address, so watch
for false rejections in the first week and raise the limit rather than adding a captcha.

**Not in this plan, on purpose:** OA push notifications on decision (message quota, and the
acknowledgement copy is already a static message), the sample shipping tracker, reject-reason
analytics, and the rep-level attribution links.
