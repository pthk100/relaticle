# KOC data model: what the real file says, and what our plan is missing

Reviewed against the file staff actually use, `Documents/TAP Creators/TAP Creator Master - 5 samples.xlsx`
(5 creators, 55 columns), plus the operator's proposed CRM/Doris split.

Verdict up front: **the split is right and our plan is incomplete.** Three of the operator's own definitions
are also wrong in ways that would produce a dashboard nobody can trust. Both lists are reconciled below,
column by column.

---

## 1. What is in the file

Five rows, one per creator, and the columns fall into six kinds that want three different homes. Naming the
kind first is what stops a 55-column sheet being copied into a 55-column table.

| Kind | Columns (as named in the sheet) | Home |
|---|---|---|
| **Identity, durable** | Handle, TikTok link, Display name, LINE ID, Phone, Contact source, KOC-KOL tier, MCN, BD owner, 画像MD link, Profile JSON link | CRM, on the person |
| **Per-cooperation** | Shipping name, Shipping address, Cooperation history (`prior_product`, `ship_sku`, `ship_date`, `list_date`, `source_admin`), Past brands | CRM, one row per sample |
| **Time series** | Followers, Avg views, Engagement rate, GMV 30d, Units sold 30d, Selling videos 30d, Avg views 30d, Engagement rate 30d %, Follower change 30d, GPM, Kalodata revenue IDR, Kalodata revenue THB est, Kalodata sales, Kalodata AOV IDR, PC GMV range, Video LIVE split | Doris |
| **Audience snapshot** | Audience gender female %, Audience age top band, Audience age split, Audience region, Audience Thailand share %, Top categories raw, Top shops, Audience locked, Avg price band | Doris |
| **Derived or editorial** | Main categories, Subcategories, Content formats, Best-selling products, Data completeness, Data status, Live seller | nowhere, computed |
| **Provenance** | Data source, Data pulled date, Last enriched date, Kalodata link, Partner Center | on every time-series row |

Two observations that decide the schema:

- **`Cooperation history` is already structured text**, one cell holding `prior_product=…; ship_sku=…;
  ship_date=16/07/25; list_date=7/14/2025; source_admin=NOEY`. That is four fields and an owner fighting to
  get out of a string. Stored as text it is unqueryable, which is why the sheet needs a human to read it.
- **The metrics are already two sources with two different windows.** On the `mooknanthida` row, the price
  band says `[PC Sep 24, 2026 - Oct 24, 2026]` while the LIVE/Video split says
  `PC (Sep 24 – Oct 24) | KD (2026-09-27..2026-10-26)`. Partner Centre and Kalodata disagree about the window
  by three days. Any table that stores one `Data_Pulled_Date` per row silently makes those numbers
  comparable when they are not.

---

## 2. Three corrections to the proposed split

**2.1 A pull date is not a measurement window.** Part B stores `Data_Pulled_Date`. That answers "when did we
scrape this", not "what 30 days does it describe". A 30-day GMV pulled on Tuesday and a 30-day GMV pulled on
Wednesday describe different windows and will differ; joined on the same key they look like a trend. Every
fact row needs `source`, `period_start`, `period_end` and `pulled_at`, and the console must show which window
it is drawing.

**2.2 Two sources, two truths, never averaged.** Partner Centre's GMV range and Kalodata's GPM are different
measurements of different populations over different windows. The sheet keeps them in parallel columns, which
is honest. The database must keep them as separate rows keyed by source, never as `gmv` with the source lost.

**2.3 The delivery status list is missing the two states that matter most.** The proposed
`Pending, Dispatched, Delivered, Video Posted` has no state for *took the sample and never posted*, and none
for *brand risk*. Those are the two states whose whole purpose is to stop a creator being pitched again.
The operational list is six: `no_response`, `sample_pending`, `sample_shipped`, `fulfilled`, `blacklisted`,
`blacklisted_brand_risk`. The blacklist states need a reason and a date, and the CRM must refuse to open a new
sample for them without an override.

---

## 3. Column map, all 55

| # | Column | Destination | Notes |
|---:|---|---|---|
| 1 | Handle | `people` custom field `tiktok_handle` | natural key, but mutable; never the join key |
| 2 | TikTok link | `people` `tiktok_profile` | already in the plan |
| 3 | Display name | `people.name` | |
| 4 | Source list | `people` `cohort` | "TNTSPACE Creator Lot3" is a cohort, not a note |
| 5–12 | Followers, Avg views, ER, GMV 30d, Units, Selling videos, Avg views 30d, ER 30d % | Doris `creator_performance` | one row per source per window |
| 13 | Avg price band | Doris `creator_performance` (low/high THB) | parse the range, keep the raw string |
| 14–15 | Main / Subcategories | derived | compute from `Top categories raw`; do not store |
| 16 | Content formats | derived | from the video/live split |
| 17 | Best-selling products | **drop** | marketing copy with prices and sales in one cell; the CRM has the real sample records |
| 18 | Audience gender female % | Doris `creator_audience` | |
| 19 | Audience age top band | derived | the max of #20 |
| 20 | Audience age split | Doris `creator_audience.age_split` JSON | |
| 21–22 | Audience region, TH share % | Doris `creator_audience` | region list is an array, store JSON |
| 23 | Live seller | derived | from the split |
| 24 | Past brands | `cooperation` rows, aggregated | each brand is a cooperation |
| 25 | Cooperation history | `cooperations` (4 fields + owner) | parse once, then never again |
| 26–27 | Kalodata link, Partner Center | provenance on the fact row | |
| 28–30 | Data source, pulled date, last enriched | provenance on the fact row | |
| 31 | Data completeness | derived | percent of non-null expected fields |
| 32 | KOC-KOL tier | `people` `koc_tier` | nano / micro / koc / kol |
| 33 | LINE ID | `people` `line_id` | exists in the platform set already |
| 34 | Contact | **drop** | duplicates #33 and #38 in free text |
| 35 | BD owner | `people` `account_owner_id` | the field already exists; this is what it is for |
| 36–37 | Profile MD, Profile JSON link | `people` `profile_md_url`, `profile_json_url` | the generated artifact, see §5 |
| 38 | Phone | `people` `phone_number` | already the unique identity field |
| 39–40 | Shipping name, address | `sample_applications` | as planned |
| 41 | Contact source | `people` `contact_source` | how we got them |
| 42 | Data status | derived | Partial / Enriched |
| 43 | GPM | Doris fact | Kalodata's own metric |
| 44 | PC GMV range | Doris fact, `source = partner_centre` | keep the band, not a point estimate |
| 45 | Video LIVE split | Doris fact, parsed per source | the cell mixes PC and KD windows; split it |
| 46–47 | Kalodata revenue IDR, THB est | Doris fact | store IDR, the rate used, and THB |
| 48 | Follower change 30d | Doris fact | or derive from the series |
| 49–50 | Top categories raw, Top shops | Doris `creator_audience` | the shops list belongs with facts about this creator |
| 51 | MCN | `people` `mcn` | "MOCA CREATIVE MEDIA" |
| 52 | Contact signals | split | `LINE:<id> | mcn_signed=true:<agency>` is two fields, not one |
| 53 | Audience locked | Doris flag | what the lock means needs a definition before storing |
| 54–55 | Kalodata sales, AOV IDR | Doris fact | |

---

## 4. What our current plan is missing

The plan and the waves docs cover the intake, the review, approval, and a Partner Centre affiliation carrying
order count, GMV and commission. Against the real file, these are the gaps:

| # | Gap | Consequence if left |
|---|---|---|
| **G1** | No `koc_tier`, `mcn`, `mcn_signed`, `cohort`, `contact_source`, `bd_owner`, `profile_*_url` on the person | every routing and pricing decision the sheet supports becomes impossible |
| **G2** | No sample fulfilment lifecycle, no tracking number, no blacklist states | a creator who took a sample and never posted gets pitched again, which is the one error the operator named explicitly |
| **G3** | No cooperation record; `Cooperation history` would land in a text field | the history stays unqueryable, which is why the sheet needs a human today |
| **G4** | No profile artifacts and no versioning of them | "durable over time" resolves to a link that rots |
| **G5** | Analytics is only Partner Centre order totals | no audience, no category mix, no follower trend: the entire enrichment value of Kalodata |
| **G6** | No Kalodata ingestion at all | half the sheet's columns have no source |
| **G7** | No source or measurement window on any metric | cross-source numbers become comparable and quietly wrong |
| **G8** | No currency handling | Kalodata quotes IDR; a THB column without the rate is an unevidenced number |
| **G9** | No defined boundary between the CRM and Doris | two stores claim the same metric |
| **G10** | `Short_URL_Sent` per opportunity is not modelled | rep and campaign attribution has nowhere to land |

---

## 5. What already exists, which changes the build

The analytics layer is **not greenfield**, and this is the part the waves doc got wrong by omission. In
`~/marketing-platform`:

| Piece | Where | Reuse |
|---|---|---|
| Doris schema | `configs/doris-schema.sql` | already defines `platform_analytics.person_profile` (UNIQUE KEY `tenant_id, person_id`), `person_key_map`, `segment_members`, `tenant_events`, `activity_log_raw` |
| Doris client + provisioning | `orchestrator/src/analytics/doris-events.ts`, `orchestrator/src/provisioners/doris.ts` | the connection, retry and statement handling already exist |
| Read route | `orchestrator/src/routes/analytics.ts` (live since `501ece6`) | `GET /api/analytics/events`, tenant-scoped from the signed session |
| Console consumer | `console/src/screens/Analytics.tsx` | the surface that would render creator performance |

So the correct shape is an **extension of `person_profile`**, not a new warehouse:

```
Relaticle (Postgres)                    marketing-platform
  people          ── koc_id ──┐            orchestrator ── mysql2 ──► Doris
  cooperations                ├─ sync ──►   ETL job                     platform_analytics
  opportunities               │            (Kalodata + Partner Centre)  creator_performance
  sample_applications ────────┘                                       creator_audience
                                                                      person_profile (exists)
                                                    console ◄── GET /api/analytics/creators
```

`koc_id` is the Relaticle person ULID, carried as `person_id`, which `person_profile` and `person_key_map`
already key on. That means no new identity scheme, which was the one thing worth avoiding.

---

## 6. Revised architecture

**CRM (Relaticle, Postgres) owns identity, operations and state transitions.** Person: handle, profile URL,
line id, phone, tier, mcn, mcn_signed, cohort, contact_source, account_owner, profile artifact links.
Cooperation: one row per sample or campaign, with SKU, dispatch date, tracking, delivery status, blacklist
reason. Opportunity: brand, campaign, short link sent, funnel status, commission rate, affiliate track id.

**Doris owns measurement.** `creator_performance` keyed `(tenant_id, koc_id, source, period_end)` and
`creator_audience` keyed `(tenant_id, koc_id, source, period_end)`, partitioned by month on `period_end`,
`UNIQUE KEY` for idempotent re-loads, following the existing `person_profile` pattern. Each row carries
`source`, `period_start`, `period_end`, `pulled_at`, and for money `amount_local`, `currency`, `fx_rate`,
`amount_thb`.

**ETL is its own idempotent loader** in the platform repo: Kalodata and Partner Centre to normalised fact
rows, keyed so a re-run replaces rather than duplicates. It never writes to Postgres, and Relaticle never
writes to Doris; each store has one writer.

---

## 7. What this adds to the build waves

Three items, one of them in a different repository, which is why they were invisible to a single-repo plan:

| New item | Repo | Depends on | Owns |
|---|---|---|---|
| **Wave 0c** the KOC profile fields | relaticle-fork | nothing | `PeopleField` additions, the backfill migration, lang keys |
| **Wave 1D** cooperation and fulfilment | relaticle-fork | Wave 0 | `cooperations` table and model, the six states, blacklist guard, tracking number |
| **Wave 2B** Kalodata → Doris ingestion | marketing-platform | Wave 0c's key, and the operator's Kalodata access | `configs/doris-schema.sql` additions, an ETL script, `GET /api/analytics/creators`, a console card |
| **Wave 0d** the sheet importer | relaticle-fork | 0c and 1D | a one-off command that reads the master sheet: people, cooperations, and the first Doris snapshot |

Wave 2B is the only item that touches a second repository, so it is the one that must not share a subagent
with anything in relaticle-fork.

---

## 8. Decisions asked on 2026-11-04, answered in §9

1. **Is `koc_id` the person ULID, or a separate creator registry?** Recommendation: the person ULID. Two
   identity schemes for one human is the expensive mistake, and `person_key_map` already exists to hold
   handle changes.
2. **What does `Audience locked` mean?** It looks like a paid-tier flag on the audience data. Storing a flag
   whose meaning is undefined is how a null becomes a "no".
3. **Which source is authoritative for GMV when both answer?** Recommendation: neither. Show both, labelled
   by source and window, and let the console state which one a headline figure came from.
4. **Blacklist override policy.** Who may clear a `blacklisted_brand_risk` state, and is it reversible?
5. **Kalodata access.** Is it a subscription with an API, or scraped? That decides whether Wave 2B is a client
   or a parser, and it is the one item that could be blocked for a reason outside the code.
6. **Refresh cadence.** Daily is plausible for 30-day metrics; hourly is pointless and doubles Doris load.

---

## 9. The answers, and what each one changes

Answered by the operator on 2026-11-04. Each answer below is a constraint a subagent must be able to apply
without asking again.

### 9.1 `KOC_ID` comes from a central Creator Registry, as a ULID

This overturns §8.1's recommendation, and the operator's version is better for a reason worth writing down:
**a creator exists in the analytics layer before they exist in the CRM.** Kalodata returns a creator we have
never contacted, and Partner Centre will surface more. If the key were `people.id`, every scraped creator
would need a CRM person row to get an ID, which fills the CRM with records nobody owns.

So the registry is its own table and it issues the key:

| Table | Key | Rule |
|---|---|---|
| `creators` | `id` ULID, this **is** `KOC_ID` | one row per human creator, created by whoever sees them first, whether that is a sample application, a Partner Centre lookup or a Kalodata import |
| `people` | `id` ULID | the CRM person, optional `creator_id` unique foreign key |
| `creator_merges` | | `from_creator_id` to `to_creator_id`, why, and by whom |

Rules that make this safe, and each one prevents a specific failure:

1. **`creators.handle` is an attribute, never the key.** Handles change; the sheet already carries a case
   where the LINE ID and the TikTok handle differ.
2. **Exactly one row per human.** A merge is an explicit record, never a delete, and every fact row keeps
   pointing at the surviving id after the merge is applied by the ETL.
3. **A creator row carries no CRM relationship.** Notes, tasks, opportunities and activity hang off the
   person. The registry holds identity only: handle, profile URL, tier, MCN, mcn_signed, cohort,
   contact_source, profile artifact links.
4. **Onboarding is the only direction.** A person is created from a creator, never the reverse, so the
   registry never has to guess a person.

Doris then keys on `koc_id`, in a new `creator_dim` plus fact tables, and leaves `person_profile` alone: that
table answers questions about CRM activity, which is a different subject from creator performance.

### 9.2 `audience_locked` is a boolean, and it is a paywall artefact

Stored as `boolean` on the audience fact row, not on the creator. It answers "did this tier include audience
data for this pull", which is a property of the measurement, not of the human. Consequence: when it is false,
the audience columns are **absent, not zero**, and the console says "not included in this subscription tier"
rather than rendering an empty chart.

### 9.3 Partner Centre is the source of truth for money; Kalodata is intelligence only

This is the strongest of the six answers and it needs to be enforced in the schema, not just documented:

- Every money field carries `source`, and only `source = partner_centre` may feed a commercial figure:
  commission, opportunity amount, payout, anything a person is paid on.
- Kalodata figures are **context**: they may be shown, compared, or used to pick who to approach. They may
  never be summed into a commercial total, and they are never used to fill a gap where Partner Centre has no
  number. A missing Partner Centre figure renders as "not reported", never as a Kalodata estimate.
- The console must label the two differently on the same screen. A single number labelled "GMV" after this
  decision is a bug, because it hides which store it came from.

### 9.4 Reversing a blacklist is a role, not a button

Two flags, not one, and the reversible one is not the dangerous one:

| Flag | Blocks | Who may clear it |
|---|---|---|
| `blacklisted_sample` (took the sample, never posted) | another sample dispatch | a rep, with a reason, which is recorded |
| `blacklisted_brand_risk` (fake followers, controversy) | any contact at all | the commercial lead only |

A reversal writes an audit entry with actor, reason and timestamp, and the guard is a policy check, not a
hidden UI path. This matches the decision already recorded in commit `34e71e3`; the registry work must reuse
it rather than invent a second scheme.

### 9.5 Kalodata is a paid subscription, API unconfirmed

So `2B` is built exactly like the Partner Centre client: an interface, a fake, and the tests run against the
fake. Whether the real implementation is an HTTP client or a parser of exported files is **deferred**, and the
interface must not assume either. The one thing that is certain is the normalised fact shape in §6.

### 9.6 The refresh cadence is configuration, daily by default

`analytics.creator_refresh_cron` (or the equivalent) with a daily default, and the tick is idempotent per
`(koc_id, source, period_end)` so a manual re-run replaces instead of duplicating. The cadence is set where
the subscription's rate limit can be seen, not hard-coded in the job.

### 9.7 BD owner is a Thailand staff member with a dual remit

The BD owner reaches out to brands to secure products **and** to creators to bring them in. Two consequences:

1. It is `account_owner_id`, a real user in the workspace, never free text, because the same person has to be
   filtered by on both a creator and a brand.
2. Both `creators` and the brand-side company carry it, so "what is Nok working on" is one query rather than
   two lists that drift.

### 9.8 The multi-brand product master is coming, and it is a fourth source

Promised from TikTok Partner shortly. It changes two things:

- `campaign` and `skus` on the sample form stop being placeholders and reference real catalog rows. The form
  must store a catalog key plus a snapshot of the label, so a later catalogue edit does not silently relabel an
  old application.
- It adds **Wave 2C**: an ingest for brands and SKUs, with its own sync key, feeding the same console. It is a
  fourth source beside Partner Centre and Kalodata, and it is the only one of the four that is a *product*
  master rather than a *party* master.

Until the file arrives, `1A` keeps its two placeholder options and the column is `catalog_key` nullable.

