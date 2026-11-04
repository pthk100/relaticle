<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

use App\Enums\CustomFieldType;

enum CompanyField: string
{
    use CustomFieldTrait;

    /**
     * Ideal Customer Profile: Indicates whether the company is the most suitable customer for you
     */
    case ICP = 'icp';

    /**
     * Domains: Website domains of the company (system field)
     */
    case DOMAINS = 'domains';

    /**
     * LinkedIn: The LinkedIn profile URL of the company
     */
    case LINKEDIN = 'linkedin';

    /**
     * Brand code: Stable code the product master and affiliate links use for this brand
     */
    case BRAND_CODE = 'brand_code';

    /**
     * Category: How the MCN team files the brand
     */
    case CATEGORY = 'category';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::ICP => 'ICP',
            self::DOMAINS => 'Domains',
            self::LINKEDIN => 'LinkedIn',
            self::BRAND_CODE => 'Brand code',
            self::CATEGORY => 'Category',
        };
    }

    public function getFieldType(): string
    {
        return match ($this) {
            self::ICP => CustomFieldType::TOGGLE->value,
            self::DOMAINS => CustomFieldType::DOMAIN->value,
            self::LINKEDIN => CustomFieldType::LINK->value,
            self::BRAND_CODE, self::CATEGORY => CustomFieldType::TEXT->value,
        };
    }

    public function isSystemDefined(): bool
    {
        return match ($this) {
            self::DOMAINS => true,
            default => false,
        };
    }

    public function isListToggleableHidden(): bool
    {
        return match ($this) {
            self::ICP, self::DOMAINS => false,
            default => true,
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ICP => 'Indicates whether this company is an Ideal Customer Profile',
            self::DOMAINS => 'Website domains of the company (e.g., example.com)',
            self::LINKEDIN => 'URL to the company\'s LinkedIn profile',
            self::BRAND_CODE => 'Stable code the product master and affiliate links use for this brand',
            self::CATEGORY => 'How the MCN team files the brand',
        };
    }

    public function allowsMultipleValues(): bool
    {
        return match ($this) {
            self::DOMAINS => true,
            default => false,
        };
    }

    public function isUniquePerEntityType(): bool
    {
        return match ($this) {
            self::DOMAINS => true,
            default => false,
        };
    }
}
