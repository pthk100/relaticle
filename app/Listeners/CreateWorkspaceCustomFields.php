<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\CustomFields\CompanyField as CompanyCustomField;
use App\Enums\CustomFields\NoteField as NoteCustomField;
use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Enums\CustomFields\PeopleField as PeopleCustomField;
use App\Enums\CustomFields\TaskField as TaskCustomField;
use App\Events\WorkspaceCreated;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\Visibility\BackendVisibilityService;

/**
 * Seeds the default custom fields of every entity into a new workspace.
 *
 * This is why the platform contact field set is inherited "by construction":
 * a workspace created through any path (the registration UI, demo:reset, or
 * the platform provisioning API) gets PeopleField's codes here, not from a
 * point-in-time migration.
 */
final readonly class CreateWorkspaceCustomFields
{
    /** @var array<class-string, class-string> */
    private const array MODEL_ENUM_MAP = [
        Company::class => CompanyCustomField::class,
        Opportunity::class => OpportunityCustomField::class,
        Note::class => NoteCustomField::class,
        People::class => PeopleCustomField::class,
        Task::class => TaskCustomField::class,
    ];

    public function handle(WorkspaceCreated $event): void
    {
        $this->seedDefaultFields($event->workspace);
    }

    private function seedDefaultFields(Workspace $workspace): void
    {
        $now = now()->toImmutable();
        $fields = [];
        $options = [];
        $entityTypes = [];

        foreach (self::MODEL_ENUM_MAP as $modelClass => $enumClass) {
            $entityType = Entities::getEntity($modelClass)?->getAlias() ?? $modelClass;
            $entityTypes[] = $entityType;

            foreach ($enumClass::cases() as $enum) {
                $rows = CustomFieldRow::build((string) $workspace->getKey(), $entityType, $enum, $now);
                $fields[] = $rows['field'];

                array_push($options, ...$rows['options']);
            }
        }

        // insert() fires no model events: the defaults every workspace starts with are
        // not an edit anyone made, so seeding them writes nothing to the audit log.
        DB::transaction(function () use ($fields, $options): void {
            CustomField::query()->insert($fields);
            CustomFieldOption::query()->insert($options);
        });

        foreach ($entityTypes as $entityType) {
            BackendVisibilityService::clearCache($entityType);
        }
    }
}
