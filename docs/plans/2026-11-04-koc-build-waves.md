# KOC intake to affiliate track: build waves for parallel subagents

Execution decomposition of `docs/plans/2026-11-04-koc-sample-intake-plan.md`, written for fan-out to
subagents that start with no context. Read that plan for the code; this document decides **who builds
what, in which order, and what each of them must prove**.

Repository: `/Users/ai.parry/dev/relaticle-fork`, branch `platform`.

---

## 1. Why waves rather than one queue

Three subagents on one repository break each other in the two ways a plan cannot protect against: two of
them edit the same file, or one of them builds on a table the other has not created yet. Both are
avoided with one rule, applied without exception:

> **Every file has exactly one owner.** A stream never edits a file another stream owns, and never
> "quickly fixes" one it notices. If a stream needs a change in someone else's file, it says so in its
> final report and the caller makes it.

Waves follow from that: the schema is a prerequisite for two streams, and one file (`lang/en/sample_application.php`)
is wanted by all three, so it is frozen up front and owned by Wave 0.

```
Wave 0 ──┬── Wave 0b (independent, comment prose only)
         │
         ├── Wave 1A  public form
         ├── Wave 1B  review and approval
         └── Wave 1C  Partner Centre
                     │
                     └── Wave 2  results sync (needs 1C's table, 1B's approval path)
```

---

## 2. Starting state, measured

| Thing | State on 2026-11-04 |
|---|---|
| Local stack | Postgres 17 + Redis in OrbStack, 172 migrations applied, `local:seed` logins created, Vite assets built, `php artisan serve` on `127.0.0.1:8900` |
| Baseline test | `php artisan test --compact tests/Feature/CRM/CompanyModelTest.php` gives 10 passed, 20 assertions |
| Arch gate | 120 passed, **1 failed**: the em-dash rule, 92 lines in 28 files. Wave 0b exists to clear it |
| Deployed host | DEV050 on `582ae5772`, verified by content hashes; container healthy; `DEPLOYED-REVISION` corrected today |
| Board | `marketing-platform`: 5 todo, 9 blocked, 16 scheduled, **0 ready**. Nothing on the board is this work yet |

Two arch-test environment notes every subagent will hit:

- `composer test:arch` crashes inside `laravel/pao` v1.1.5 under PHP 8.5. Use
  `PAO_DISABLE=1 php -d memory_limit=1024M vendor/bin/pest tests/Arch --no-tia`.
- `vendor/bin/pint` cannot run here: the gateway blocks it on a 1 MiB scan cap for the binary. Write
  formatted code by hand and say in the report that pint was unavailable.

---

## 3. Wave 0: schema and model foundation

**Owns these files, and only these.**

| File | What it adds |
|---|---|
| `database/migrations/2026_11_04_130000_create_sample_applications_table.php` | the table |
| `database/migrations/2026_11_04_130500_add_tiktok_profile_contact_field.php` | backfills the new contact field |
| `app/Models/SampleApplication.php` | model, casts, relations, workspace scope |
| `app/Enums/SampleApplicationStatus.php` | submitted, approved, rejected |
| `app/Enums/CustomFields/PeopleField.php` | the `TIKTOK_PROFILE` case and every `match` arm it needs |
| `database/factories/SampleApplicationFactory.php` | factory |
| `lang/en/sample_application.php` | **every key all four later streams need**, form copy included, Thai beside English |

**Why the lang file is here and not in the streams.** Three streams want to add keys to it and two would
collide. One author writes all of them now, from the plan's `form.fields` block plus the `nav`, `action`,
`status`, `decision`, `received` and `partner` groups. A later stream that needs a key names it in its report.

**Exit criteria, each able to fail.**

1. `php artisan migrate --force` applies both migrations, then a second run is a no-op.
2. `php artisan test --compact tests/Feature/Sample/SampleApplicationModelTest.php` passes, including the
   workspace-scope test that fails if `#[ScopedBy(WorkspaceScope::class)]` is missing.
3. `php artisan test --compact tests/Feature/Sample/PeopleTiktokProfileFieldTest.php` passes.
4. `vendor/bin/phpstan analyse app/Enums/CustomFields/PeopleField.php` reports `[OK] No errors`, which is
   what proves every exhaustive `match ($this)` got its new arm.
5. `php -r "require 'vendor/autoload.php';"` is not the check: the real one is that a workspace created by
   `Workspace::factory()->create()` has the `tiktok_profile` custom field, asserted in the factory test.

---

## 4. Wave 0b: the em-dash sweep

**Owns:** `app/Support/Platform/*`, `app/Socialite/*`, `app/Policies/WorkspacePolicy.php`,
`app/Providers/*`, `app/Http/Controllers/Platform/*`, `app/Actions/Platform/*`, `app/Support/ConsentMirror/*`,
`routes/api.php`, `packages/SystemAdmin/**`, and the remaining offenders.
**Does not own:** `routes/web.php` (Wave 1A needs it, and this wave runs concurrently).

**Rule for the work, which the arch test enforces:** rewrite the sentence, never swap the character for a
comma. The repo's own file says it: a comma "keeps the cadence and fixes nothing", two sentences or a colon
is the shape. The standalone `'—'` data glyph is allowed and is not an offender.

**Exit criteria.**

```bash
PAO_DISABLE=1 php -d memory_limit=1024M vendor/bin/pest tests/Arch --no-tia
# Tests: 120 passed (0 failed)
```

That is the whole gate: this is the only failing rule left, so a green run here means the branch's arch
suite is green for the first time today.

**Conflict note.** `.ai/guidelines/relaticle/writing.md` is the rule's source and is not edited by this wave.
If a sentence cannot be rewritten without changing its meaning, the wave reports the line and leaves it; a
wrong sentence in a comment is worse than a red test that names it.

---

## 5. Wave 1A: the public form

**Owns:** `routes/web.php`, `app/Http/Controllers/Sample/SampleApplicationController.php`,
`app/Http/Requests/Sample/SubmitSampleApplicationRequest.php`, `resources/views/sample/*`,
`app/Actions/Sample/SubmitSampleApplication.php`,
`app/Models/Workspace.php` (the `apply_form_token` fillable, hidden and helper only),
`database/migrations/*add_apply_form_token_to_workspaces_table.php`,
`tests/Feature/Sample/PublicSampleFormTest.php`, `tests/Feature/Sample/SubmitSampleApplicationTest.php`.

**Depends on:** Wave 0 (model, status enum, lang keys).

**Exit criteria.**

1. `GET /apply/<32 hex>` returns 200 and shows the form; an unknown token is 404; a workspace with no token
   is 404 even when its slug is guessed.
2. Every one of the eight questions is required: the data-provider test names all eight.
3. `POST` creates exactly one row and redirects to `/apply/<token>/received`.
4. Throttling uses `ThrottleBeforeAuthentication`, not the stock `throttle` middleware, and the route sits
   **outside** the `auth` group.

---

## 6. Wave 1B: review and approval

**Owns:** `app/Policies/SampleApplicationPolicy.php`, `app/Actions/Sample/ApproveSampleApplication.php`,
`app/Actions/Sample/RejectSampleApplication.php`,
`app/Filament/Resources/SampleApplicationResource.php` and its `Pages/` folder,
`tests/Feature/Sample/SampleApplicationPolicyTest.php`, `ApproveSampleApplicationTest.php`,
`RejectSampleApplicationTest.php`, `SampleApplicationResourceTest.php`.

**Depends on:** Wave 0. **Calls, but does not edit:** `App\Actions\People\CreatePeople`,
`App\Actions\Opportunity\CreateOpportunity`.

**Exit criteria.**

1. Approving creates a person and an opportunity, links both, stamps the reviewer and the decision time.
2. Approving an already-decided application is refused with 409; rejecting without a reason is refused.
3. A member of another workspace cannot view the application (the workspace-scope test).
4. The Filament page sees the submitted record under `Filament::setTenant($workspace)`.
5. The approval queues `SyncCreatorAffiliationJob`; `Queue::fake()` asserts it rather than sleeping.

**Open decision this stream must not invent.** The commission rate passed to the sync is `0.0`, because the
form asks nowhere for it and the platform's own rate wins. If that changes, it changes here and in the plan.

---

## 7. Wave 1C: Partner Centre

**Owns:** `app/Contracts/PartnerCentre/PartnerCentreClient.php`,
`app/Data/PartnerCentre/{CreatorAffiliationData,ResultTotals}.php`,
`app/Enums/PartnerCreatorStatus.php`, `app/Services/PartnerCentre/*`,
`app/Models/CreatorAffiliation.php`, `database/migrations/*create_creator_affiliations_table.php`,
`app/Actions/PartnerCentre/SyncCreatorAffiliation.php`, `app/Jobs/PartnerCentre/SyncCreatorAffiliationJob.php`,
`config/services.php`, `.env.example`.

**Depends on:** Wave 0. Runs beside 1B; the job it exposes is dispatched by 1B, which is why the job class is
built here and only *called* there.

**Exit criteria.**

1. Every test runs against `FakePartnerCentreClient`; no test touches the network.
2. A platform failure is stored on the row as `sync_error` and does not throw, so an outage never loses an
   approval that already happened.
3. The job binds the workspace with `CurrentWorkspace::within()`, proven by a test that binds nothing
   ambient and still writes the row.
4. `config/services.php` gains both the `partner_centre` block and the `line.liff_id` entry.

**Known unfinished part, to be stated in the report rather than hidden.** The two endpoint paths and the
header signature in `HttpPartnerCentreClient` are placeholders written from the shape of the capability,
not from TikTok's documentation, which was not available. The class is not done until someone with
credentials replaces them, and a green suite does not make it done.

---

## 8. Wave 2: results sync

**Owns:** `app/Actions/PartnerCentre/SyncAffiliationResults.php`,
`app/Console/Commands/SyncPartnerCentreResultsCommand.php`,
`bootstrap/app.php` (the `withSchedule` block only),
`tests/Feature/Sample/SyncAffiliationResultsTest.php`, `SyncPartnerCentreResultsCommandTest.php`.

**Depends on:** Wave 1C (the table and the client).

**Exit criteria.**

1. Totals land on the affiliation row; an affiliation with no track id is skipped without an error.
2. `php artisan platform:sync-partner-centre-results` prints `Synced N affiliations.` and exits 0.
3. The schedule entry sits inside the existing `withSchedule()` closure in `bootstrap/app.php`, never in
   `routes/console.php`.

---

## 9. Wave 3: operator wiring (not a subagent's job)

LIFF app registration under the same Provider as the OA, the rich-menu button retarget from the Lark URL to
`https://liff.line.me/{liffId}`, `LINE_LIFF_ID` in the environment, and the acceptance walk on a phone that
has not applied before. No test can verify any of it, and a subagent cannot reach the console.

---

## 10. Subagent brief template

Each spawn needs all of this, because the child sees nothing of this conversation:

```
Repository: /Users/ai.parry/dev/relaticle-fork, branch platform. Work in place.
Read first, in this order:
  1. AGENTS.md sections: Actions, Queries, One fact one owner, Business language, Database, Quality Checks
  2. docs/plans/2026-11-04-koc-sample-intake-plan.md, the tasks listed below for your stream
  3. docs/plans/2026-11-04-koc-build-waves.md, section for your wave

Your stream: <name>. You own exactly these files and nothing else: <list>.
A file you need that is not on your list: report it, do not edit it.

Test loop, after every task: write the failing test, run it and see it fail, implement, run it and see it
pass, then commit with the message given in the plan.
  php artisan test --compact <paths>
  PAO_DISABLE=1 php -d memory_limit=1024M vendor/bin/pest tests/Arch --no-tia   (before you finish)
pint cannot run on this machine (gateway scan cap). Match the surrounding style by hand.

Do not: edit files outside your list, run composer test:pest or the full suite, touch DEV050, change
config/services.php outside your wave if you are not 1C, or invent a value the plan leaves open.

Report, in this order: what you built, the exact commands you ran and their output, what you could not do
and why, which files you wanted to edit but did not own, and anything the plan got wrong.
```

---

## 11. Orchestration rules for the caller

1. **Wave 0 lands before any Wave 1 spawn.** It is the only hard dependency.
2. **Wave 1's three streams run concurrently but commit separately.** One commit per stream, so a bad stream
   is revertible without dragging the others.
3. **Merge order is 1A, 1C, 1B.** `1B` calls the job `1C` defines; merging in that order keeps every
   intermediate commit runnable.
4. **A stream that fails its exit criteria is re-run, not patched by the caller.** Patching from outside
   reintroduces the file-ownership problem the whole model exists to prevent.
5. **The arch suite runs once at the end**, after all streams merge, because two streams cannot both
   legitimately make it green in isolation.

---

## 12. Open decisions, which no subagent may settle

| Decision | Why it blocks a stream | Recommendation |
|---|---|---|
| Commission rate on the form | `1B` passes `0.0` today | leave as `0.0`; the platform's rate is authoritative until staff negotiate a rate before application |
| `campaign` and `skus` option values | `1A` renders `Pending catalog` and `sku-default` placeholders | needs a settings screen before launch; not this wave |
| Consent mirroring | the application stores evidence only | keep it evidence-only; agreeing to be contacted about one application is not marketing opt-in |
| Sample status replies | out of scope here | OA push costs message quota; the acknowledgement copy is already static |

---

## 13. Locked on 2026-11-04, and the waves this document is missing

The operator answered the open questions. The full reasoning is in
`docs/plans/2026-11-04-koc-data-model-gap-analysis.md` §9; what matters here is that these are now
**constraints, not choices**, and the wave list grows by four:

| New item | Repo | Depends on | Owns |
|---|---|---|---|
| **Wave 0c** Creator Registry and profile fields | relaticle-fork | nothing | `creators` table and model, `creator_merges`, the `PeopleField` additions, `people.creator_id`, the backfill migration |
| **Wave 0d** the master sheet importer | relaticle-fork | 0c, 1D | one command that reads the working sheet into creators, people and cooperations |
| **Wave 1D** cooperation and fulfilment | relaticle-fork | 0c | `cooperations` table, the six delivery states, the two blacklist flags and their policy guards, tracking number |
| **Wave 2C** the multi-brand product master | marketing-platform | the file from TikTok Partner | brand and SKU ingest, its own sync key, the console surface |

Constraints every stream inherits:

1. **`KOC_ID` is `creators.id`, a ULID, issued by the registry.** Never `people.id`, and never the handle. A
   creator may exist with no person row, because Kalodata sees creators before we contact them.
2. **Money comes from Partner Centre only.** A Kalodata figure may be shown as context and may never fill a
   missing commercial number. Every money field carries `source`, and a single unlabelled "GMV" is a bug.
3. **`audience_locked` is a boolean on the audience fact row.** When false, the audience columns are absent and
   the console says so; they are never rendered as zero.
4. **Two blacklist flags, two different authorities.** `blacklisted_sample` is cleared by a rep with a reason;
   `blacklisted_brand_risk` by the commercial lead only. Reuse the decision in commit `34e71e3`.
5. **Kalodata is behind an interface with a fake**, exactly like Partner Centre, because the API is unconfirmed.
6. **Refresh cadence is configurable, daily by default**, and the tick is idempotent per
   `(koc_id, source, period_end)`.
7. **BD owner is `account_owner_id`, a workspace user**, on both the creator and the brand.

Wave 0 is therefore larger than §3 says: it now includes the registry, which everything downstream keys on.
That makes Wave 0 the critical path and no longer a short one. Split it if a single subagent cannot hold it:
`0a` schema and model, `0b` registry, with `0a` first because the tables reference `creators.id`.

Still blocked on inputs, not on design:

- the **product master file** from TikTok Partner, for Wave 2C and for the real `campaign`/`skus` options;
- confirmation of whether **Kalodata** exposes an API on the subscription, which decides client versus parser
  but changes nothing above the interface.

---

## 14. Revised on 2026-11-04 after reading the two working files

Three corrections, all from operator input or from the files themselves. Detail in
`docs/plans/2026-11-04-tap-product-master-model.md`.

**Wave 2C is not a catalogue import.** The product sheet is a sourcing pipeline, not a SKU list: shops,
campaigns, products, the campaign-product join with a per-SKU brand status, versioned commission snapshots,
contact channels and BD decisions. Its importer is a parser with a rejection report, because the same cell holds
up to eight campaign ids, three shop names, or a price range.

**Two BD owners, not one.** `creator_bd_owner_id` on the creator and `brand_bd_owner_id` on the brand or shop.
The same person may hold both, which is how the current team covers creator outreach when it needs hands. A
merged column cannot answer "who owns the brand relationship" once two people share the work.

**`2B` is an importer of collected data, not a client.** Kalodata sells no API at this tier and the data is
gathered by agents from the subscription, so every import carries `collected_by`, `collected_at`,
`subscription_tier` and its source file. There is no rate limit to schedule against; the cadence is collection.

One addition that touches the creator side, and it is the kind of bug that costs money: **the opportunity must
copy the commission in force at agreement time, not reference the campaign's live rate.** The product file shows
the rate changing (Partner Centre 10%, agreed 20%, change pending), so a foreign key to a mutable rate is not a
record of what was agreed. Add this to `1D` and to the Partner Centre stream.


