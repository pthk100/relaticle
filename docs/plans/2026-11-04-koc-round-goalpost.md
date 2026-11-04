# KOC round: the goalpost

The same instrument the email module used, applied to this round. `scripts/goalpost.py` exists because design work
kept being declared done inside a turn and then found broken by the operator; it writes the judgement down as
numbers and runs them against the built surface, so the verdict does not depend on who is looking.

This document is the **frozen target**. The script that measures it is written during Waves 1B and 1D, not before,
because a measurement of a screen that does not exist is a wish with a table around it.

---

## 1. The vision, as five claims that can fail

Before the screen rows, the round has to be judged against what it is for. Each sentence below is falsifiable,
and the rest of the document turns each into measurable rows.

| # | The vision says | It is false if |
|---|---|---|
| **V1** | One creator, one identity, one key | a creator exists twice because one was created from a sample form and one from a Kalodata import |
| **V2** | Money is Partner Centre; Kalodata is context and says so | a screen shows one unlabelled "GMV", or a Kalodata figure fills a missing commercial number |
| **V3** | No creator work starts before the affiliate link is verified | a campaign can be briefed or sampled while `verified_pct` is empty |
| **V4** | A creator who took a sample and never posted is not pitched again without a recorded reversal | a second sample can be dispatched with no reason on record |
| **V5** | The CRM holds operations; performance lives in Doris with its measurement window attached | a performance number appears with no period, or a metric is stored as a column on the creator |

Wave 2C's product pipeline is judged by V2 and V3 only. Doris (V5) can be a later round if it is not deployed.

## 2. The claims

Targets are written so a script can fail them. `Measured` and `Verdict` are filled by the run, exactly like
`design/GOALPOST-STATUS.md`.

### A. Identity and the registry

| Check | Target | Measured | Verdict |
|---|---|---|---|
| a sample application creates one creator, not two | applying twice with the same handle yields 1 creator row | | |
| a creator can exist with no person row | a Kalodata-imported creator renders in the registry with `people.creator_id` null | | |
| the handle is not the key | renaming a creator's handle keeps its `koc_id` and all its facts | | |
| a merge is recorded, not deleted | merging A into B writes a `creator_merges` row and repoints facts; the A row still exists | | |
| two BD owners are distinguishable | a creator and a brand can have different owners, and one person can hold both | | |

### B. Commission and the verification gate

| Check | Target | Measured | Verdict |
|---|---|---|---|
| three numbers are shown as three | the campaign shows published, negotiated and verified, each labelled | | |
| the verified rate is the one that pays | the opportunity's payable rate equals `verified_pct`, and comes from a snapshot id | | |
| a campaign cannot be worked before verification | with `verified_pct` empty, the brief and sample actions are absent or refused | | |
| a mismatch blocks rather than averages | `verified < negotiated` produces a blocked campaign with a reason naming both numbers | | |
| a verification carries its evidence | every verification row has a link, a percentage, `verified_by`, `verified_at` | | |
| a later rate change does not reprice a past deal | changing the campaign rate leaves an existing opportunity's payable rate unchanged | | |

### C. The selection guard

| Check | Target | Measured | Verdict |
|---|---|---|---|
| the dilution test | a 10% SKU added to a campaign verified at 30% is refused | | |
| the viability test | a SKU below the workspace floor is refused | | |
| the reason is readable | the refusal names the number that failed, on the screen that triggered it | | |
| the guard is not bypassed by import | the bulk importer produces the same refusals as the form | | |
| scope is extras only | adding the first SKU to a campaign is not refused by this guard | | |

### D. Fulfilment and the blacklists

| Check | Target | Measured | Verdict |
|---|---|---|---|
| the six states exist | no_response, sample_pending, sample_shipped, fulfilled, blacklisted_sample, blacklisted_brand_risk | | |
| `blacklisted_sample` blocks a dispatch | a second sample is refused, and a rep may clear it with a reason | | |
| `blacklisted_brand_risk` blocks contact | the contact and sample actions are refused, and only the commercial lead may clear it | | |
| a reversal is auditable | clearing either flag writes actor, reason and timestamp | | |
| an unavailable product stays visible | a product missing from Open Products is marked unavailable, not removed, and an existing deal still names it | | |

### E. Provenance, which is where drift actually happens

| Check | Target | Measured | Verdict |
|---|---|---|---|
| every money figure is labelled by source | unlabelled money elements = 0 | | |
| Kalodata never totals into a commercial figure | summing commercial figures includes 0 Kalodata rows | | |
| a missing Partner Centre number reads as missing | the cell says "not reported", never a Kalodata estimate | | |
| performance numbers carry their window | every Doris-sourced figure shows its period, or says "not included in this tier" | | |
| an unverified rate is not shown as fact | an unverified campaign renders its rate as pending, not as a number | | |

### F. Layout, so the screens do not drift from the approved design

These are the rows the existing email goalpost taught us to write: geometry and behaviour, measured, not judged.

| Check | Target | Measured | Verdict |
|---|---|---|---|
| the creator profile fits its viewport | 0 elements out of bounds, 0 document overflow at 1742/1440/1280/1024 | | |
| the review queue keeps one row height | every row within ±2px of the first | | |
| the commission block does not wrap mid-number | no percentage or currency token breaks across lines at any of the four widths | | |
| a state chip is a chip, not a control | chip height ≤ 32px against the 48px control | | |
| the blacklist banner is unmissable | it precedes the record's body, and its action is disabled rather than hidden | | |
| empty, loading and error states exist | three distinct states on the profile and the queue | | |
| tokens are the console's | 0 unknown CSS custom properties in the built surface | | |
| the vision check: not blank | stddev > 6 and white < 98% per screen capture | | |

## 3. What this instrument cannot do

It cannot tell you whether a screen looks right. A row can pass while the page is ugly, and the existing script
says so in its own header. Two consequences here:

1. The captures still get looked at, by a person, at two widths.
2. **The target has to exist before the build, or this measures drift against itself.** There is no approved KOC
   screen design yet. If the round is built straight into Filament defaults, the goalpost can only pin the
   defaults, which is the opposite of what it is for.

So the sequence is: freeze the design, then build, then run the goalpost.

## 4. Runbook

```bash
# in ~/dev/marketing-console, the existing harness
.qa/bin/python scripts/goalpost.py            # email round, exit 0 = all pass
.qa/bin/python scripts/koc-goalpost.py        # this round, once the screens exist
.qa/bin/python scripts/koc-goalpost.py --json
```

Exit codes follow the existing convention: `0` every row passed, `1` at least one FAIL, `2` could not measure
(not logged in, unreachable, no record to inspect). `2` is never reported as a pass.

## 5. What I need before writing the script

1. **Where do these screens live?** The Relaticle Filament panel, the marketing console (React), or both? The
   existing harness drives `localhost:8080`, which is the console; a Filament panel needs a different harness and
   a login fixture.
2. **Which screens are in this round?** My assumption: creator profile, review queue, campaign and product board,
   opportunity. Confirm or cut.
3. **Is Doris in scope for round one?** V5 and the window rows can only be measured if the analytics surface ships
   with this round; otherwise they are `n/a` and the round is judged on V1 to V4.
4. **Whose eye signs the captures off?** The goalpost removes the operator from noticing measured defects; someone
   still has to look at the images, and it should be named rather than implied.
