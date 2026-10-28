<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;
use App\Onboarding\PlatformFieldOptions;

/**
 * People custom field codes.
 *
 * This enum is the platform's contact contract: every code here is seeded into
 * every workspace (see CreateWorkspaceCustomFields) and backfilled into
 * workspaces that predate it (see the seed_platform_contact_fields migration),
 * so an orchestrator can write the same codes for every tenant without
 * discovering a workspace's schema first.
 *
 * Twenty-six codes in three bands, in sort order:
 *
 *   1-9    identity and channel handles (emails, phone_number, whatsapp_number,
 *          line_id, wechat_id, kakaotalk_id, preferred_channel, job_title, linkedin)
 *   10-21  profile (department, seniority, country, preferred_language, timezone,
 *          lead_source, products_interest, segments, next_follow_up,
 *          account_owner, birthday, lifecycle_stage)
 *   30-34  consent mirrors (marketing_optin, dnc, optout_reason, jurisdiction,
 *          lawful_basis)
 *
 * `lifecycle_stage` (t_7273de85) sits at the END of the profile band on purpose:
 * it is DERIVED (see `App\Support\LifecycleStage`), so it is the one code in this
 * enum that no caller ever writes by hand, and giving it a sort order inside the
 * authored fields would invite exactly that.
 *
 * The consent mirrors are a read-only projection of the platform's consent
 * store: t_a8dd1bae writes them, humans do not. marketing_optin is deliberately
 * a select with four values and never a toggle: "not_asked" must not be
 * readable as "granted", and a boolean collapses exactly that distinction.
 */
enum PeopleField: string
{
    use CustomFieldTrait;

    // Band 1: identity and channel handles.
    case EMAILS = 'emails';
    case PHONE_NUMBER = 'phone_number';
    case WHATSAPP_NUMBER = 'whatsapp_number';
    case LINE_ID = 'line_id';
    case WECHAT_ID = 'wechat_id';
    case KAKAOTALK_ID = 'kakaotalk_id';
    case PREFERRED_CHANNEL = 'preferred_channel';
    case JOB_TITLE = 'job_title';
    case LINKEDIN = 'linkedin';

    // Band 2: profile.
    case DEPARTMENT = 'department';
    case SENIORITY = 'seniority';
    case COUNTRY = 'country';
    case PREFERRED_LANGUAGE = 'preferred_language';
    case TIMEZONE = 'timezone';
    case LEAD_SOURCE = 'lead_source';
    case PRODUCTS_INTEREST = 'products_interest';
    case SEGMENTS = 'segments';
    case NEXT_FOLLOW_UP = 'next_follow_up';
    case ACCOUNT_OWNER = 'account_owner';
    case BIRTHDAY = 'birthday';

    // Derived, never authored (t_7273de85) - the platform's lead/customer marker.
    case LIFECYCLE_STAGE = 'lifecycle_stage';

    // Band 3: consent mirrors.
    case MARKETING_OPTIN = 'marketing_optin';
    case DNC = 'dnc';
    case OPTOUT_REASON = 'optout_reason';
    case JURISDICTION = 'jurisdiction';
    case LAWFUL_BASIS = 'lawful_basis';

    /**
     * The four values the platform store holds. Never a boolean: "not_asked"
     * is a distinct state and must stay one at the CRM boundary.
     */
    public const array MARKETING_OPTIN_VALUES = ['granted', 'declined', 'unknown', 'not_asked'];

    /**
     * Every code the reason select offers, in display order. The platform
     * (`orchestrator/src/consent/reasons.ts`) writes these and nothing else.
     */
    public const array OPTOUT_REASON_VALUES = [
        // the reason the person gave
        'too_frequent',
        'not_relevant',
        'never_signed_up',
        'competitor',
        'no_longer_customer',
        'privacy_concern',
        // how the withdrawal arrived, when no reason was given
        'unsubscribe_link',
        'user_request',
        'admin',
        // delivery-triggered
        'hard_bounce',
        'complaint',
        // the single fallback
        'other',
    ];

    /**
     * `lead_source` offers ORIGIN only, never a channel (t_7273de85).
     *
     * The first taxonomy mixed two axes: `LINE` and `WhatsApp` are channels, and
     * the channel is already carried by `preferred_channel` plus the per-channel
     * ids (`line_id`, `wechat_id`, ...). One field cannot hold both without
     * becoming unqueryable - "how many live-chat leads?" would have to union four
     * channel values - so LINE / WhatsApp / Web widget were replaced by the
     * origin they were standing in for.
     *
     * Option name => human label. The NAME is what an integration writes (a
     * select's value is its option name in this CRM), so it is snake_case: a value
     * with a space or a capital is the first thing the next writer gets wrong.
     * The label is documentation - the fork's `custom_field_options` row has one
     * `name` column and no separate label, so the CRM renders `live_chat` exactly
     * as it already renders `not_asked` and `legitimate_interest`.
     *
     * @var array<string, string>
     */
    public const array LEAD_SOURCE_OPTIONS = [
        'live_chat' => 'Live chat',
        'member_signup' => 'Member sign-up',
        'web_form' => 'Web form',
        'referral' => 'Referral',
        'event' => 'Event',
        'ads' => 'Ads',
        'import' => 'Import',
        'manual' => 'Manual',
    ];

    /**
     * The four `lifecycle_stage` values, in precedence order for a reader.
     *
     * Option name => human label, for the same reason as LEAD_SOURCE_OPTIONS. The
     * VALUES themselves are owned by `App\Support\LifecycleStage\LifecycleStage`,
     * which is the single definition of what each one means; this constant only
     * fixes the option rows the select offers.
     *
     * @var array<string, string>
     */
    public const array LIFECYCLE_STAGE_OPTIONS = [
        'lead' => 'Lead',
        'opportunity' => 'Opportunity',
        'customer' => 'Customer',
        'lost' => 'Lost',
    ];

    /**
     * The five fields the platform's consent store PROJECTS onto the contact.
     *
     * A human must not be able to set them (see
     * `App\Support\ConsentMirror\ConsentMirrorGuard`), because a CRM row saying
     * `granted` for somebody who withdrew is the dangerous direction: the send
     * gate reads the platform store, so the CRM copy would be a lie nothing
     * corrects. Only the platform's own platform-key-guarded endpoint writes them.
     *
     * @return list<string>
     */
    public static function consentMirrorCodes(): array
    {
        return [
            self::MARKETING_OPTIN->value,
            self::DNC->value,
            self::OPTOUT_REASON->value,
            self::JURISDICTION->value,
            self::LAWFUL_BASIS->value,
        ];
    }

    public function getFieldType(): string
    {
        return match ($this) {
            self::EMAILS => CustomFieldType::EMAIL->value,
            self::PHONE_NUMBER, self::WHATSAPP_NUMBER => CustomFieldType::PHONE->value,
            self::LINKEDIN => CustomFieldType::LINK->value,
            self::PREFERRED_CHANNEL, self::SENIORITY, self::COUNTRY, self::PREFERRED_LANGUAGE,
            self::TIMEZONE, self::LEAD_SOURCE, self::MARKETING_OPTIN, self::OPTOUT_REASON,
            self::JURISDICTION, self::LAWFUL_BASIS, self::LIFECYCLE_STAGE => CustomFieldType::SELECT->value,
            self::PRODUCTS_INTEREST => CustomFieldType::MULTI_SELECT->value,
            self::SEGMENTS => CustomFieldType::TAGS_INPUT->value,
            self::NEXT_FOLLOW_UP => CustomFieldType::DATE_TIME->value,
            self::BIRTHDAY => CustomFieldType::DATE->value,
            self::DNC => CustomFieldType::TOGGLE->value,
            self::LINE_ID, self::WECHAT_ID, self::KAKAOTALK_ID, self::DEPARTMENT,
            self::ACCOUNT_OWNER, self::JOB_TITLE => CustomFieldType::TEXT->value,
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::EMAILS => 'Emails',
            self::PHONE_NUMBER => 'Phone Number',
            self::WHATSAPP_NUMBER => 'WhatsApp Number',
            self::LINE_ID => 'LINE ID',
            self::WECHAT_ID => 'WeChat ID',
            self::KAKAOTALK_ID => 'KakaoTalk ID',
            self::PREFERRED_CHANNEL => 'Preferred Channel',
            self::JOB_TITLE => 'Job Title',
            self::LINKEDIN => 'LinkedIn',
            self::DEPARTMENT => 'Department',
            self::SENIORITY => 'Seniority',
            self::COUNTRY => 'Country',
            self::PREFERRED_LANGUAGE => 'Preferred Language',
            self::TIMEZONE => 'Timezone',
            self::LEAD_SOURCE => 'Lead Source',
            self::PRODUCTS_INTEREST => 'Products of Interest',
            self::SEGMENTS => 'Segments',
            self::NEXT_FOLLOW_UP => 'Next Follow-up',
            self::ACCOUNT_OWNER => 'Account Owner',
            self::BIRTHDAY => 'Birthday',
            self::LIFECYCLE_STAGE => 'Lifecycle Stage',
            self::MARKETING_OPTIN => 'Marketing Opt-in',
            self::DNC => 'Do Not Contact',
            self::OPTOUT_REASON => 'Opt-out Reason',
            self::JURISDICTION => 'Jurisdiction',
            self::LAWFUL_BASIS => 'Lawful Basis',
        };
    }

    /**
     * Platform-owned field DEFINITIONS: the tenant must not be able to delete one
     * and silently break a projection.
     *
     * The five consent mirrors project the platform's consent record;
     * `lifecycle_stage` is derived from the workspace's own opportunities by
     * `App\Support\LifecycleStage`. A deleted `lifecycle_stage` would leave the
     * projection writing into nothing, which is why it is in the set - it does NOT
     * make its OPTIONS the platform's: this field's four values are declared above
     * and a tenant that curated them would break the derivation, so the guard in
     * `LifecycleStageGuard` covers the field definition too.
     *
     * Everything else is a profile field the tenant curates (options included).
     */
    public function isSystemDefined(): bool
    {
        return match ($this) {
            self::EMAILS, self::MARKETING_OPTIN, self::DNC, self::OPTOUT_REASON,
            self::JURISDICTION, self::LAWFUL_BASIS, self::LIFECYCLE_STAGE => true,
            default => false,
        };
    }

    /**
     * Is this field a consent MIRROR - written only by the platform, never by a
     * human? Defined from `consentMirrorCodes()` so the read-only guard and the
     * option list cannot drift apart.
     */
    public function isConsentMirror(): bool
    {
        return in_array($this->value, self::consentMirrorCodes(), true);
    }

    /**
     * Is this field DERIVED by the platform from other CRM data, so a human edit
     * is a lie rather than a contribution?
     *
     * `lifecycle_stage` is the only one today: it is a fold over the contact's
     * opportunities (`App\Support\LifecycleStage`), so a hand-typed `customer` on
     * somebody with no won deal is exactly the confusion the field was added to
     * remove. The guard refuses the edit; the reconciliation overwrites whatever
     * slips past it.
     */
    public function isDerived(): bool
    {
        return $this === self::LIFECYCLE_STAGE;
    }

    public function isListToggleableHidden(): bool
    {
        return match ($this) {
            // The derived stage is the answer to "is this person in the pipeline?",
            // so it belongs in the table a person scans - hiding it would put the
            // address book and the pipeline back in one list.
            self::JOB_TITLE, self::EMAILS, self::PREFERRED_CHANNEL, self::NEXT_FOLLOW_UP, self::DNC, self::LIFECYCLE_STAGE => false,
            default => true,
        };
    }

    /**
     * The three new columns anyone scans. The four identity fields a workspace
     * already showed in its list (emails, phone_number, job_title, linkedin) keep
     * their column: seeding must not silently drop the Emails column out of the
     * people table. Everything else the set adds stays out of the list
     * (visible_in_view only) so nineteen new inputs do not bury it.
     */
    public function isVisibleInList(): bool
    {
        return match ($this) {
            self::EMAILS, self::PHONE_NUMBER, self::JOB_TITLE, self::LINKEDIN,
            self::PREFERRED_CHANNEL, self::DNC, self::NEXT_FOLLOW_UP, self::LIFECYCLE_STAGE => true,
            default => false,
        };
    }

    public function getSortOrder(): int
    {
        return match ($this) {
            self::EMAILS => 1,
            self::PHONE_NUMBER => 2,
            self::WHATSAPP_NUMBER => 3,
            self::LINE_ID => 4,
            self::WECHAT_ID => 5,
            self::KAKAOTALK_ID => 6,
            self::PREFERRED_CHANNEL => 7,
            self::JOB_TITLE => 8,
            self::LINKEDIN => 9,
            self::DEPARTMENT => 10,
            self::SENIORITY => 11,
            self::COUNTRY => 12,
            self::PREFERRED_LANGUAGE => 13,
            self::TIMEZONE => 14,
            self::LEAD_SOURCE => 15,
            self::PRODUCTS_INTEREST => 16,
            self::SEGMENTS => 17,
            self::NEXT_FOLLOW_UP => 18,
            self::ACCOUNT_OWNER => 19,
            self::BIRTHDAY => 20,
            self::LIFECYCLE_STAGE => 21,
            self::MARKETING_OPTIN => 30,
            self::DNC => 31,
            self::OPTOUT_REASON => 32,
            self::JURISDICTION => 33,
            self::LAWFUL_BASIS => 34,
        };
    }

    public function allowsMultipleValues(): bool
    {
        return match ($this) {
            self::EMAILS => true,
            self::PRODUCTS_INTEREST, self::SEGMENTS => true,
            default => false,
        };
    }

    public function getMaxValues(): int
    {
        return match ($this) {
            self::EMAILS => 5,
            self::PRODUCTS_INTEREST => 10,
            self::SEGMENTS => 20,
            default => 1,
        };
    }

    /**
     * The upsert ladder is one identity field per data type: emails (email)
     * and phone_number (phone). whatsapp_number is explicitly NOT unique:
     * "we conversed on WhatsApp from this number" is not "this person's
     * identity is this number".
     */
    public function isUniquePerEntityType(): bool
    {
        return match ($this) {
            self::EMAILS, self::PHONE_NUMBER => true,
            default => false,
        };
    }

    /**
     * @return array<int|string, string>|null
     */
    public function getOptions(): ?array
    {
        return match ($this) {
            self::PREFERRED_CHANNEL => [
                'WhatsApp', 'LINE', 'WeChat', 'KakaoTalk', 'SMS', 'Email', 'Phone',
            ],
            self::SENIORITY => [
                'Owner', 'C-level', 'Director', 'Manager', 'Staff',
            ],
            // ISO 3166-1 alpha-2 codes, rendered "Name (CC)" so the stored value
            // is both readable in the CRM and machine-parseable at the boundary.
            // array_values: FieldOptionRows reads the KEY as the option's
            // sort_order, so an associative list would write the name there.
            self::COUNTRY => array_values(PlatformFieldOptions::countries()),
            self::PREFERRED_LANGUAGE => [
                'en', 'zh-Hant', 'zh-Hans', 'th', 'vi', 'id', 'ms', 'ja', 'ko', 'tl',
            ],
            // IANA zone identifiers plus GMT as the explicit unknown. The field
            // cannot default itself (the settings vocabulary has no
            // default_value); defaulting is a write-path rule.
            self::TIMEZONE => array_values(PlatformFieldOptions::timezones()),
            self::LEAD_SOURCE => array_keys(self::LEAD_SOURCE_OPTIONS),
            self::LIFECYCLE_STAGE => array_keys(self::LIFECYCLE_STAGE_OPTIONS),
            self::MARKETING_OPTIN => self::MARKETING_OPTIN_VALUES,
            // Two bands: the REASON the person gave, then how the withdrawal
            // arrived when they gave none (the mechanism is a fact even when the
            // reason is not), then the delivery-triggered codes, then the ONE
            // fallback.
            //
            // The platform writes exactly these codes (`consent/reasons.ts` in
            // marketing-platform, pinned by `ConsentMirrorFieldSetTest`): anything
            // it cannot recognise becomes `other` rather than a value this select
            // does not offer.
            self::OPTOUT_REASON => self::OPTOUT_REASON_VALUES,
            self::JURISDICTION => [
                'HK PDPO', 'EU GDPR', 'UK GDPR', 'TH PDPA', 'SG PDPA', 'MY PDPA',
                'ID PDPA', 'PH DPA', 'CN PIPL', 'BR LGPD', 'AU Privacy Act',
                'US CCPA/CPRA', 'Other',
            ],
            self::LAWFUL_BASIS => [
                'consent', 'contract', 'legitimate_interest', 'vital_interest',
                'public_task', 'legal_obligation', 'not_applicable',
            ],
            default => null,
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::PHONE_NUMBER => 'Mobile number. Unique per workspace, so it is a legal upsert match field.',
            self::WHATSAPP_NUMBER => 'The number this contact messaged us from on WhatsApp. Not an identity key; never unique.',
            self::LINE_ID => 'The contact\'s LINE user id, as received from the messaging channel.',
            self::WECHAT_ID => 'The contact\'s WeChat id.',
            self::KAKAOTALK_ID => 'The contact\'s KakaoTalk id.',
            self::PREFERRED_CHANNEL => 'The channel the contact prefers to be reached on.',
            self::COUNTRY => 'ISO 3166-1 alpha-2, stored as "Name (CC)".',
            self::PREFERRED_LANGUAGE => 'BCP-47 language tag, e.g. en, zh-Hant, th.',
            self::TIMEZONE => 'IANA zone identifier ("Asia/Hong_Kong"). GMT is the explicit unknown, never a guess at a zone.',
            self::LEAD_SOURCE => 'How this contact first reached us - the ORIGIN (live chat, a member sign-up form, a web form, ...), never the channel: the channel is preferred_channel plus the per-channel ids.',
            self::LIFECYCLE_STAGE => 'Derived from this contact\'s opportunities: Lead (no deal), Opportunity (an open deal), Customer (a Closed Won deal), Lost (only Closed Lost deals). Read-only - it follows the pipeline by itself.',
            self::PRODUCTS_INTEREST => 'Product lines this contact is interested in. Options are workspace-curated.',
            self::SEGMENTS => 'Free-form segmentation tags for this contact.',
            self::NEXT_FOLLOW_UP => 'When a human owes this contact the next reply.',
            self::ACCOUNT_OWNER => 'The teammate who owns this account, by name or email. Text, not a record relation: the record field type can only target CRM entities in this build, not users.',
            self::BIRTHDAY => 'Date of birth. Optional; do not infer it.',
            self::MARKETING_OPTIN => 'Mirror of the platform consent store. Four values: granted, declined, unknown, not_asked. Not asked is NOT declined and NOT granted.',
            self::DNC => 'Do-not-contact flag from the platform consent store. When set, no outbound message may be sent.',
            self::OPTOUT_REASON => 'Why the contact opted out, from the platform consent store.',
            self::JURISDICTION => 'The privacy regime that governs this contact, from the platform consent store.',
            self::LAWFUL_BASIS => 'The legal basis the platform recorded for processing this contact\'s data.',
            default => null,
        };
    }
}
