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
 * Twenty-five codes in three bands, in sort order:
 *
 *   1-9    identity and channel handles (emails, phone_number, whatsapp_number,
 *          line_id, wechat_id, kakaotalk_id, preferred_channel, job_title, linkedin)
 *   10-20  profile (department, seniority, country, preferred_language, timezone,
 *          lead_source, products_interest, segments, next_follow_up,
 *          account_owner, birthday)
 *   30-34  consent mirrors (marketing_optin, dnc, optout_reason, jurisdiction,
 *          lawful_basis)
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

    public function getFieldType(): string
    {
        return match ($this) {
            self::EMAILS => CustomFieldType::EMAIL->value,
            self::PHONE_NUMBER, self::WHATSAPP_NUMBER => CustomFieldType::PHONE->value,
            self::LINKEDIN => CustomFieldType::LINK->value,
            self::PREFERRED_CHANNEL, self::SENIORITY, self::COUNTRY, self::PREFERRED_LANGUAGE,
            self::TIMEZONE, self::LEAD_SOURCE, self::MARKETING_OPTIN, self::OPTOUT_REASON,
            self::JURISDICTION, self::LAWFUL_BASIS => CustomFieldType::SELECT->value,
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
            self::MARKETING_OPTIN => 'Marketing Opt-in',
            self::DNC => 'Do Not Contact',
            self::OPTOUT_REASON => 'Opt-out Reason',
            self::JURISDICTION => 'Jurisdiction',
            self::LAWFUL_BASIS => 'Lawful Basis',
        };
    }

    /**
     * Only the five consent mirrors are platform-owned field definitions: they
     * project the platform's consent record, so a tenant must not be able to
     * delete one and silently break the mirror. Everything else is a profile
     * field the tenant curates (options included).
     */
    public function isSystemDefined(): bool
    {
        return match ($this) {
            self::EMAILS, self::MARKETING_OPTIN, self::DNC, self::OPTOUT_REASON,
            self::JURISDICTION, self::LAWFUL_BASIS => true,
            default => false,
        };
    }

    public function isListToggleableHidden(): bool
    {
        return match ($this) {
            self::JOB_TITLE, self::EMAILS, self::PREFERRED_CHANNEL, self::NEXT_FOLLOW_UP, self::DNC => false,
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
            self::PREFERRED_CHANNEL, self::DNC, self::NEXT_FOLLOW_UP => true,
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
            self::LEAD_SOURCE => [
                'LINE', 'WhatsApp', 'Web widget', 'Referral', 'Event', 'Ads', 'Import',
            ],
            self::MARKETING_OPTIN => self::MARKETING_OPTIN_VALUES,
            self::OPTOUT_REASON => [
                'user_request', 'unsubscribe_link', 'hard_bounce', 'complaint', 'admin', 'other',
            ],
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
            self::LEAD_SOURCE => 'How this contact first reached us.',
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
