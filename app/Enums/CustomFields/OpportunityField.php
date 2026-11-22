<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;

enum OpportunityField: string
{
    use CustomFieldTrait;

    case AMOUNT = 'amount';
    case CLOSE_DATE = 'close_date';
    case STAGE = 'stage';

    // KOC collaboration mirrors: the platform projects the collaboration into
    // these, and a hand edit is overwritten by the next projection.
    case KOC_STAGE = 'koc_stage';
    case PRODUCT_SKU = 'product_sku';
    case OPERATOR = 'operator';
    case SAMPLE_TRACKING = 'sample_tracking';
    case AFFILIATE_LINK = 'affiliate_link';
    case LIVE_POST_URL = 'live_post_url';

    /**
     * The collaboration ladder's own vocabulary. `stage` stays the sales
     * pipeline: it offers none of these values.
     *
     * @var list<string>
     */
    public const array KOC_STAGE_VALUES = [
        self::KOC_STAGE_PITCH,
        self::KOC_STAGE_SAMPLE_PENDING,
        self::KOC_STAGE_SHIPPED,
        self::KOC_STAGE_DELIVERED,
        self::KOC_STAGE_FULFILLED,
    ];

    /**
     * A pitch nobody has agreed to yet, so the pipeline views hide it by default.
     */
    public const string KOC_STAGE_PITCH = 'no_response';

    public const string KOC_STAGE_SAMPLE_PENDING = 'sample_pending';

    public const string KOC_STAGE_SHIPPED = 'shipped';

    public const string KOC_STAGE_DELIVERED = 'delivered';

    public const string KOC_STAGE_FULFILLED = 'fulfilled';

    /**
     * The rung a person reads, by the value the platform writes.
     *
     * The stored option NAME is the platform's own vocabulary: the projector
     * posts `sample_pending` and the API resolves that name back to an option
     * id, so a label cannot live in `custom_field_options.name`. It is mapped
     * here, at render time, and the stored value never moves.
     *
     * @return array<string, string>
     */
    public static function kocStageLabels(): array
    {
        return [
            self::KOC_STAGE_PITCH => __('filament/resources/opportunity.fields.koc_stage.options.no_response'),
            self::KOC_STAGE_SAMPLE_PENDING => __('filament/resources/opportunity.fields.koc_stage.options.sample_pending'),
            self::KOC_STAGE_SHIPPED => __('filament/resources/opportunity.fields.koc_stage.options.shipped'),
            self::KOC_STAGE_DELIVERED => __('filament/resources/opportunity.fields.koc_stage.options.delivered'),
            self::KOC_STAGE_FULFILLED => __('filament/resources/opportunity.fields.koc_stage.options.fulfilled'),
        ];
    }

    /**
     * A name outside the ladder passes through unchanged: an operator who
     * renamed an option already wrote the words a person should read.
     */
    public static function kocStageLabel(string $value): string
    {
        return self::kocStageLabels()[$value] ?? $value;
    }

    /**
     * @return string[]|null
     */
    public function getOptions(): ?array
    {
        return match ($this) {
            self::STAGE => [
                'Prospecting',
                'Qualification',
                'Needs Analysis',
                'Value Proposition',
                'Id. Decision Makers',
                'Perception Analysis',
                'Proposal/Price Quote',
                'Negotiation/Review',
                'Closed Won',
                'Closed Lost',
            ],
            self::KOC_STAGE => self::KOC_STAGE_VALUES,
            default => null,
        };
    }

    public function getFieldType(): string
    {
        return match ($this) {
            self::AMOUNT => CustomFieldType::CURRENCY->value,
            self::CLOSE_DATE => CustomFieldType::DATE->value,
            self::STAGE => CustomFieldType::SELECT->value,
            self::KOC_STAGE => CustomFieldType::RADIO->value,
            self::AFFILIATE_LINK, self::LIVE_POST_URL => CustomFieldType::LINK->value,
            self::PRODUCT_SKU, self::OPERATOR, self::SAMPLE_TRACKING => CustomFieldType::TEXT->value,
        };
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::AMOUNT => 'Amount',
            self::CLOSE_DATE => 'Close Date',
            self::STAGE => 'Stage',
            self::KOC_STAGE => 'KOC Stage',
            self::PRODUCT_SKU => 'Product SKU',
            self::OPERATOR => 'Operator',
            self::SAMPLE_TRACKING => 'Sample Tracking',
            self::AFFILIATE_LINK => 'Affiliate Link',
            self::LIVE_POST_URL => 'Live Post URL',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::KOC_STAGE => 'Where this collaboration stands on the KOC ladder. Mirrored from the platform; Stage stays the sales pipeline.',
            self::OPERATOR => 'The operator written by the platform. The authoritative assignment is the task assignee.',
            self::PRODUCT_SKU => 'The SKU the sample and the affiliate link belong to.',
            self::SAMPLE_TRACKING => 'The fulfilment tracking number, mirrored from the platform.',
            self::AFFILIATE_LINK => 'The live affiliate link for this collaboration. Earlier links stay in the platform.',
            self::LIVE_POST_URL => 'The published post, mirrored from the platform.',
            default => null,
        };
    }

    public function isListToggleableHidden(): bool
    {
        return false;
    }

    public function getSortOrder(): int
    {
        return match ($this) {
            self::AMOUNT => 1,
            self::CLOSE_DATE => 2,
            self::STAGE => 3,
            self::KOC_STAGE => 4,
            self::PRODUCT_SKU => 5,
            self::OPERATOR => 6,
            self::SAMPLE_TRACKING => 7,
            self::AFFILIATE_LINK => 8,
            self::LIVE_POST_URL => 9,
        };
    }

    /**
     * Get color mapping for select field options
     *
     * 2024 Sophisticated Sales Journey - A unique emotional progression through the
     * pipeline using earth-inspired, professional colors that tell the story of
     * building energy, trust, and momentum from first contact to final outcome.
     * Based on latest color psychology and 2024 design sophistication trends.
     *
     * @return array<string, string>|null Array of option => color mappings or null if not applicable
     */
    public function getOptionColors(): ?array
    {
        return match ($this) {
            self::STAGE => [
                'Prospecting' => '#a5b4fc',           // Misty Dawn - Soft exploration of possibilities
                'Qualification' => '#1e40af',         // Ocean Depth - Deep analytical thinking
                'Needs Analysis' => '#0d9488',        // Teal Understanding - Empathy & insight
                'Value Proposition' => '#eab308',     // Golden Clarity - Bright ideas & value
                'Id. Decision Makers' => '#7c3aed',   // Royal Authority - Power & influence
                'Perception Analysis' => '#f59e0b',   // Warm Amber - Comfortable evaluation
                'Proposal/Price Quote' => '#1e293b',  // Professional Navy - Serious business
                'Negotiation/Review' => '#f97316',    // Electric Coral - Dynamic energy
                'Closed Won' => '#059669',            // Victory Emerald - Success celebration
                'Closed Lost' => '#6b7280',           // Silver Acceptance - Respectful closure
            ],
            self::KOC_STAGE => [
                self::KOC_STAGE_PITCH => '#6b7280',
                self::KOC_STAGE_SAMPLE_PENDING => '#a5b4fc',
                self::KOC_STAGE_SHIPPED => '#eab308',
                self::KOC_STAGE_DELIVERED => '#0d9488',
                self::KOC_STAGE_FULFILLED => '#059669',
            ],
            default => null,
        };
    }

    /**
     * The KOC mirrors describe a collaboration we run, not a sales deal a brand
     * tenant owns, so they belong to the operations workspace alone.
     */
    public function belongsToOperationsWorkspace(): bool
    {
        return match ($this) {
            self::KOC_STAGE,
            self::PRODUCT_SKU,
            self::OPERATOR,
            self::SAMPLE_TRACKING,
            self::AFFILIATE_LINK,
            self::LIVE_POST_URL => true,
            self::AMOUNT,
            self::CLOSE_DATE,
            self::STAGE => false,
        };
    }
}
