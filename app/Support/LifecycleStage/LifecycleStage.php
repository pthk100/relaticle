<?php

declare(strict_types=1);

namespace App\Support\LifecycleStage;

/**
 * THE definition of `lifecycle_stage` (t_7273de85).
 *
 * ── Derived, never authored ─────────────────────────────────────────────────
 * The requester's problem was that the address book and the pipeline were the
 * same list: a member who signed up and a prospect with a live deal looked
 * identical. `lifecycle_stage` is the column that separates them, and it is not
 * an opinion anybody types - it is a fold over the contact's opportunities, so it
 * cannot disagree with the deals it summarises.
 *
 * ── The precedence rule, in one place ───────────────────────────────────────
 * Called with the stage of every LIVE opportunity the contact has:
 *
 *   1. any at `Closed Won`                              -> customer
 *   2. else any open one (anything that is not closed)  -> opportunity
 *   3. else only `Closed Lost`                          -> lost
 *   4. else no opportunities at all                     -> lead
 *
 * `lead` and `opportunity` are deliberately distinct. A member sign-up is a lead
 * with no deal; a live-chat sales enquiry is an opportunity. Collapsing them is
 * the state the requester is trying to leave.
 *
 * ── Why this class and nothing else ─────────────────────────────────────────
 * There is exactly ONE implementation of the rule in the tree, and every writer
 * goes through it: the observer that fires on a stage change, the reconciliation
 * sweep, and any future call site. A second copy - in an API resource, a Filament
 * column, a report - is how the CRM ends up with two answers to one question, and
 * `tests/Feature/LifecycleStage/` fails the build if the rule is duplicated
 * outside this file (`grep -rn "'Closed Won'" app/` in the evidence doc).
 *
 * ── The stage is matched by LABEL ───────────────────────────────────────────
 * `stage` is an opportunity custom field whose options are seeded from
 * `OpportunityField::STAGE` - the same ten labels in every workspace (the CRM's
 * seeded set, not a tenant's invention), which is also what the platform's
 * opportunity-creation path writes (`OPPORTUNITY_STAGE = 'Prospecting'`). Matching
 * the label, case-insensitively, is therefore correct fleet-wide. A workspace that
 * RENAMED `Closed Won` would make every won deal read as open, so the two closed
 * labels are constants here and the reconciliation reports them: rename the option
 * and you change this file, deliberately.
 */
final readonly class LifecycleStage
{
    /** No opportunity at all: the contact is in the address book, not the pipeline. */
    public const string LEAD = 'lead';

    /** An open deal exists. */
    public const string OPPORTUNITY = 'opportunity';

    /** A deal was won. */
    public const string CUSTOMER = 'customer';

    /** Every deal was lost, and none is open. */
    public const string LOST = 'lost';

    /** The opportunity stage option that means "won". */
    public const string CLOSED_WON_STAGE = 'Closed Won';

    /** The opportunity stage option that means "lost". */
    public const string CLOSED_LOST_STAGE = 'Closed Lost';

    /**
     * The fold. `$opportunityStages` holds one entry per live opportunity, and a
     * null entry for an opportunity whose stage is unset - an opportunity with no
     * stage is still an open opportunity, not a closed one.
     *
     * @param  iterable<int, string|null>  $opportunityStages
     */
    public static function fromOpportunityStages(iterable $opportunityStages): string
    {
        $won = self::normalise(self::CLOSED_WON_STAGE);
        $lost = self::normalise(self::CLOSED_LOST_STAGE);

        $sawAny = false;
        $sawOpen = false;

        foreach ($opportunityStages as $stage) {
            $sawAny = true;

            if ($stage === null) {
                // An opportunity with no stage: nothing says it is closed, so it is
                // open. Rule 2 ("anything that is not Closed Won/Closed Lost").
                $sawOpen = true;

                continue;
            }

            $normalised = self::normalise($stage);

            if ($normalised === $won) {
                return self::CUSTOMER;
            }

            if ($normalised !== $lost) {
                $sawOpen = true;
            }
        }

        if ($sawOpen) {
            return self::OPPORTUNITY;
        }

        // Only Closed Lost opportunities, or none at all. Those are different
        // facts, and a person who was pitched and declined must not read as a
        // fresh lead.
        return $sawAny ? self::LOST : self::LEAD;
    }

    /**
     * The comparison form of a stage label: trimmed, and case-folded because the
     * option is what an operator sees, not what they must spell. `mb_strtolower`
     * so a non-ASCII label cannot fold differently on two hosts.
     */
    public static function normalise(string $stage): string
    {
        return mb_strtolower(trim($stage));
    }

    /** @return list<string> the four values, in precedence order */
    public static function values(): array
    {
        return [self::LEAD, self::OPPORTUNITY, self::CUSTOMER, self::LOST];
    }
}
