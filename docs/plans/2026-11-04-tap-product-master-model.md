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

## 9. Open questions

1. **Is a campaign ever shared across brands?** The pet campaign spans three Pethealer shops, which suggests one
   seller may own several shops. If a campaign can span different brands, `campaign.brand_id` is wrong and the
   brand lives on the shop instead.
2. **Who owns the commission number of record** when Partner Centre, the brief and the BD note disagree? The file
   shows all three. Recommendation: `agreed` wins for paying, `partner_centre` wins for reporting, and the
   difference is surfaced rather than resolved.
3. **What is the selection threshold** in rule three (the "commission/selection threshold")? It is referenced and
   never defined, so it cannot be coded.
4. **Do product-level commission overrides exist**, or is commission always per campaign? The file is consistent
   per campaign, but the schema should answer this before it is needed.
5. **What happens to a product that disappears from Open Products** (`未找到 / Not found`)? Retire the row, or keep
   it historical? Recommendation: keep it, marked unavailable, because a creator may already hold a sample of it.
