# TAP product master: what the file actually models

Read from `Documents/TAP Brands/TAP Ready-6 samples.xlsx`, the sheet BD staff use now. Three sheets, 1,919
lines: `Ready to Sell (>=15%)`, `Campaign Summary`, and `Ready to Sell BACKUP 2026-11--1`.

Verdict: this is **not a product catalogue**. It is a sourcing and negotiation pipeline, and modelling it as
`brands` plus `skus` would lose the four things staff actually work with every day: the campaign, the commission
terms, the BD decision, and the approval status per SKU.

---

## 1. The three sheets are one pipeline at three grains

| Sheet | Grain | Answers |
|---|---|---|
| `Campaign Summary` | one campaign | which shop, how many products, what commission, who to contact, when last synced |
| `Ready to Sell (>=15%)` | one campaign × product | is this SKU worth pitching, at what commission, and has the brand confirmed it |
| `BACKUP 2026-11--1` | one product, ranked | the same rows on an earlier pull, ordered by priority score |

The sheets are the same data at different resolutions, which is why they drift. One normalised set of tables
should produce all three views, and the "BACKUP" sheet should stop existing as a hand-kept artefact.

## 2. Entities

```
brands ─── shops ─── campaigns ─── campaign_products ─── products
             │           │                │
             │           │                └── brand_contact_status (per SKU row)
             │           └── commission_snapshots (versioned)
             └── contact_channels (verified / unverified)
```

**`shops`** is the seller on TikTok Shop (`Pethealer-Thailand`, `Pethealer.shop`, `Pethealer-0371`, `MINISO TH
Shop`, `SOLMET TH`). One brand can operate several shops, and the file treats them as distinct because
commission and campaigns differ per shop. A shop exists before any campaign: the `(no campaign)` rows are
prospects with contact channels only.

**`campaigns`** is the affiliate campaign: a 19-digit `campaign_id`, a name that is Thai, Chinese or English on
the same sheet, a collab link, a deadline buried in the notes, and commission terms. **A campaign can span
several shops** (`Pethealer-0371, Pethealer-Thailand, Pethealer.shop` share one campaign name) and one shop
appears under several campaigns, so this is many-to-many in both directions.

**`products`** is the SKU: 19-digit product id, title, price in THB, rating, cumulative sold, free-sample
availability, and the TikTok product link.

**`campaign_products`** is the join, and it is the row staff edit. It carries `brand_contact_status`
(Confirmed / Pending / Rejected), `priority_score`, `rank_tier` (P1/P2/P3 plus a rank), the Kalodata 30-day
figures recorded against that product, and the BD decision.

## 3. Commission is versioned, and this is the most important finding

The sheet carries `Open Commission %`, `Targeted Commission %`, `TAP Creator Commission %`, `Shop Ads %`,
`Free Sample`, `Commission updated` and `Previous rate`. The MINISO rows show why all three exist:

> Partner Center 现定向 10% 待改到 20%; Coy 工作定向 20%

Partner Centre currently says 10%, the agreed working rate is 20%, and a change is pending. A single
`commission_rate` column on a campaign would be wrong within a week.

So commission is a **snapshot series**:

```
commission_snapshots
  campaign_id, product_id (nullable = campaign-wide)
  open_pct, targeted_pct, tap_creator_pct, shop_ads_pct, free_sample
  effective_from, recorded_at, source (partner_centre | agreed | brief)
  previous_rate_text
```

And the rule that follows from it, which is the correction this document exists to make:

> **A deal copies the commission in force at agreement time onto the opportunity. It never joins to the live
> campaign rate.**

The file already demonstrates the failure: paying a creator on today's rate would pay 10% where the agreement was
20%, or the reverse. A foreign key to a mutable rate is not a record of what was agreed.

## 4. The four rules in the `Process rules (Coy)` cell are application logic

That cell is a business rulebook written as prose, approved 2026-11-03. Each rule is enforceable:

| Rule | Where it belongs |
|---|---|
| Collaboration ID is mandatory on every row | `campaigns.collab_id` NOT NULL; a row without one is a prospect, not a campaign |
| Brand contact status is per SKU row: Confirmed / Pending / Rejected | `campaign_products.brand_contact_status`, never on the shop or campaign |
| Extra SKUs in the same shop are allowed only when the campaign matches **and** the commission/selection threshold is met; otherwise ask the group first | a guard on adding `campaign_products`: same campaign plus threshold check, else a pending-approval state |
| Partial multi-SKU approval is recorded per SKU; never approve or reject a whole shop | no bulk status write across a shop, and the UI must not offer one |

The last two are the ones that will be re-invented as a convenience button if they are not written down.

## 5. Derived, therefore not stored

`Gap`, `Multiple (Targeted ÷ Open)`, `Product count`, `Rank / Tier`, `Data completeness`, and the ">=15%" in the
sheet name are all computed. `Priority Score` is a judgement, so it is stored **with the version of the formula
that produced it**: without that, a rank from last month cannot be explained when selection is questioned.

## 6. The file already implements the Partner Centre rule

`Sales Basis` is a column, and it distinguishes `Units Sold` from `Kalodata 30d (2026-09-29~10-28)`. The sheet
keeps `Units Sold` and `Kalodata 30d Units` in parallel columns rather than one merged figure, and it writes down
which basis a row used.

That is the same decision recorded in the gap analysis §9.3, arrived at independently by the staff. It is
evidence that the rule is operationally correct, not a design preference, and it should be enforced in the
importer: a product metric row carries `source` and `period`, and `Units Sold` never fills a Kalodata field.

## 7. Data quality the importer must survive

Measured on the file, not hypothetically:

- `Campaign ID(s)` holds **eight ids in one cell** for the pet campaign, comma separated.
- `Seller / Shop(s)` holds **three shop names** in one cell.
- Titles mix Thai, Chinese and English, with truncation (`...`) inside the same column.
- `[check]` markers mark unverified contacts, and `未找到 / Not found` marks a product missing from Open Products.
- `TBC` and `待BD确认` are both used as "not decided", so a status enum needs an explicit `undecided` value rather
  than treating them as blank.
- Prices are sometimes a range (`฿99–419`) and sometimes a point (`฿229`).

So the importer is a parser with a report, not a straight load. Every row it cannot parse must appear in a
rejection report with the cell text, because a silently dropped SKU is a missed commission.

## 8. What this changes in the build

**Wave 2C is not a catalogue import.** It becomes a sourcing pipeline: shops, campaigns, products, the
campaign-product join with its per-SKU status, commission snapshots, contact channels, and BD decisions, plus an
importer that reads this file shape and reports what it could not parse.

**The creator side gains a rule.** The opportunity must carry `campaign_id`, `product_id` and a **copy** of the
commission snapshot at agreement time. That is a change to `1D` and to the Partner Centre stream, and it is
cheaper now than after deals exist.

**Two BD owners, per §9.7 of the gap analysis.** `brand_bd_owner_id` on the brand or shop, `creator_bd_owner_id`
on the creator, and one person may hold both.

## 9. The answers, and the two definitions they fix

### 9.1 The opportunity is a KOC × brand match, and the campaign is not the brand

Corrected by the operator: **an opportunity is one commercial match between a single KOC and a single
brand or product line.** If a KOC sells Brand A well and Brand B also fits, that is a **new campaign** and a
**second opportunity**, and the creator may eventually carry both.

That means the brand does not live on the campaign:

| Entity | Carries the brand? |
|---|---|
| `shops` | **yes** (`brand_id`), because a seller can run several shops for one brand |
| `products` | **yes**, inherited from the shop and overridable |
| `campaigns` | **no**, because the file already shows one agency campaign id, `7701938009231410950`, spanning both `Oritina-TH` and `MINISO TH Shop`, which are different brands |
| `opportunities` | **yes**, and it is the only place that binds one KOC to one brand or product line |

So `campaigns` reaches brands through `campaign_shops`, never through a `brand_id` column. A campaign is a
TikTok-side grouping that may span shops and brands; the opportunity is our commercial record.

### 9.2 Commission is negotiated, and the number of record is the one on the link we verified

The public TikTok rate is a **starting position**, not a term, and the operator is explicit that it is often
simply wrong: every rate is negotiated case by case, and where the agency is confident of volume it can reach
50% or more depending on the brand. The published listing therefore has no evidentiary value at all beyond "a
conversation is possible".

So three numbers exist, and they are not the same fact:

| Number | Source | Role |
|---|---|---|
| `published_pct` | Partner Centre, as offered | the opening position; never a term |
| `negotiated_pct` | the BD's agreement with the brand | what we believe we will be paid |
| `verified_pct` | the configured affiliate link, read by us | **the number of record**, because it is what TikTok will actually pay |

And the gate that follows, which is the whole point of the answer:

```
published  ->  negotiated  ->  link configured  ->  VERIFIED  ->  work may start
```

- A campaign may not move to `ready_to_sell`, and a creator may not be briefed or sampled, until
  `verified_pct` has been recorded against it.
- Each verification stores `verified_at`, `verified_by`, the affiliate link or campaign URL that was read, and
  the percentage seen. A verification without the artefact it was read from is a rumour.
- If `verified_pct` differs from `negotiated_pct`, that is a **blocked campaign with a reason**, escalated to the
  brand. It is never silently accepted, and it is never averaged.
- `verified_pct` supersedes both other numbers for payment, because it is the only one that pays.

This is the same shape as the earlier rule about copying the commission onto the deal: the opportunity stores
`verified_pct` **at agreement time**, plus the snapshot id it came from, so a later change to the campaign cannot
reprice a deal that already exists.

### 9.3 Product-level overrides exist, because the link is configured per product

The commission is configured on the affiliate link, and links are per product or product line, so the override
lives on `campaign_products` and a campaign-wide rate is the default rather than the only value. The
verification gate therefore runs per campaign-product, not once per campaign.

### 9.4 A product that disappears from Open Products is retained

Marked `unavailable`, never deleted, because a creator may already hold a sample of it and the deal it was part
of has to remain explainable.

## 10. The selection guard: two factors, and it returns a reason

Answered by the operator: the threshold in process rule three is a **two-factor guard**, not one number.

| Factor | Column | Rejects when | Why |
|---|---|---|---|
| Commercial | commission percentage | below the system baseline (for example under 10.0%) | TikTok restricts promotion mechanics below a floor, and a thin margin does not pay for a creator's video or the agency's cut |
| Inventory | `minimum_priority_score` (integer) | below the floor | a slow-moving or low-priority SKU breaks fulfilment when it is added to an active campaign |

So the guard is:

```
add an extra SKU to an existing campaign
  1. same campaign          product.campaign_id == campaign.id
  2. same shop              product.shop_id    == campaign.shop_id
  3. commission             rate >= config.minimum_commission_pct     (default 10.0)
  4. priority               priority_score >= config.minimum_priority_score
  5. availability           product is not out of stock
  => Allow, or Refuse with the failed reasons
```

Four notes that matter more than the shape:

1. **It returns reasons, never a bare boolean.** A validation that answers `False` produces a support ticket
   saying "why can't I add this one?", and the person answering has to redo the arithmetic. It returns
   `Refuse(['commission 8.0% is below the 10.0% floor'])`, so the refusal is readable on the screen that
   triggered it.
2. **The commission it checks is the verified rate, not the published one.** A SKU whose link pays 8% while the
   public listing says 14% passes a published-rate check and fails in reality. If the rate has not been verified
   yet, the guard refuses with "not verified", because an unverified rate is not a fact (see §9.2).
3. **`minimum_priority_score` is a proxy for stock, and the file has no stock column.** The sheet's
   `Priority Score` runs roughly 58 to 89 and is derived from sales and units, not from warehouse quantity. If
   the real intent is "do not promise an out-of-stock product", a sales-derived score cannot detect it; either an
   inventory field is added to the master, or the guard's rationale is restated as "low demand", which is what the
   number actually measures.
4. **The score is a decimal in the file** (76.9, 88.9, 62.6), so an integer floor silently rounds. Either keep it
   decimal or state the rounding rule; otherwise two SKUs at 59.4 and 59.6 behave differently for no reason the
   BD can see.

The guard is one class in `app/Actions/`, the single owner of this rule, called by the add-SKU action, the bulk
importer and any API path. Enforcement in the form only means the next import bypasses it.

## 11. The baseline is per campaign, and extras-only is provisional

### 11.1 The commission floor is not one global number

Because every rate is negotiated and the published listing is unreliable, a single workspace-wide floor such as
10% is only a **viability** test, not the real selection test. The question the guard should actually answer is
"does this extra SKU dilute the campaign it is joining?", and the answer is a comparison with the campaign's own
rate:

```
effective_floor = max( workspace.minimum_commission_pct , campaign.verified_pct )

refuse when  candidate.verified_pct < effective_floor
```

Two tests, two purposes, both needed:

| Test | Threshold | Rejects |
|---|---|---|
| Viability | workspace floor (10% today) | a SKU too thin to pay for a creator's video or the agency's cut |
| Dilution | the campaign's own verified rate | a 10% SKU added to a campaign already paying 30%, which spends the creator's time on the worse product |

A workspace-wide 30% floor would be wrong for a brand whose whole catalogue sits at 12%, which is exactly what
the operator described: rates are negotiated per brand, sometimes far above the list.

### 11.2 The guard applies to extras only, for now

Confirmed by the operator, with a note to re-check later. Recorded here as **provisional**, because the
consequence is that a weak first SKU enters a campaign through a door the guard does not watch:

> **Revisit before Wave 2C ships.** Decide whether the same guard applies to the first SKU. Until then the guard
> is scoped to `add extra SKU to an existing campaign` and the first SKU is a BD judgement.

### 11.3 The BD who receives the link verifies it

The verification in §9.2 is performed by the BD who obtained the affiliate link from the brand. That is a
single-person check with no second pair of eyes, which is an accepted throughput trade, and it is only honest if
the evidence is captured:

- the link or campaign URL that was read,
- the percentage seen on it,
- `verified_by` and `verified_at`,
- and, for a mismatch, the brand conversation reference.

Because the same person negotiates and verifies, the artefact is the control. A verification row with no link
behind it is not a verification, and the commercial lead should be able to audit a sample of them without asking
anyone what happened.



