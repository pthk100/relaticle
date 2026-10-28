<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\NoteField;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use Carbon\CarbonImmutable;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

/**
 * Builds the custom_field + custom_field_option rows one field definition
 * becomes in one workspace.
 *
 * Shared by the workspace-created listener (which seeds a whole new workspace)
 * and by the backfill applier (which seeds an existing one), so the two can
 * never drift: there is one declaration of what a field row looks like.
 */
final readonly class CustomFieldRow
{
    /**
     * @return array{
     *     field: array<string, mixed>,
     *     options: list<array<string, mixed>>
     * }
     */
    public static function build(
        string $tenantId,
        string $entityType,
        CompanyField|OpportunityField|PeopleField|TaskField|NoteField $enum,
        CarbonImmutable $now,
    ): array {
        $field = new CustomField;
        $id = (string) $field->newUniqueId();

        $row = $field->forceFill([
            'id' => $id,
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'code' => $enum->value,
            'name' => $enum->getDisplayName(),
            'type' => $enum->getFieldType(),
            'lookup_type' => null,
            'width' => $enum->getWidth(),
            'sort_order' => $enum->getSortOrder(),
            'active' => true,
            'system_defined' => $enum->isSystemDefined(),
            'settings' => new CustomFieldSettingsData(
                visible_in_list: $enum->isVisibleInList(),
                list_toggleable_hidden: $enum->isListToggleableHidden(),
                enable_option_colors: $enum->hasColorOptions(),
                allow_multiple: $enum->allowsMultipleValues(),
                max_values: $enum->getMaxValues(),
                unique_per_entity_type: $enum->isUniquePerEntityType(),
                description: $enum->getDescription(),
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ])->getAttributes();

        return [
            'field' => $row,
            'options' => FieldOptionRows::build(
                $tenantId,
                $id,
                (string) $row['type'],
                $enum->getOptions() ?? [],
                $enum->getOptionColors() ?? [],
                $now,
            ),
        ];
    }
}
