<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Rules\ArrayExistsForWorkspace;
use Illuminate\Validation\Rule;

final class StoreTaskRequest extends BaseCrmEntityRequest
{
    protected function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        $workspace = $user->currentWorkspace;
        $workspaceId = $workspace->getKey();
        // Everyone in the workspace EXCEPT the platform's own identity: it is a member so
        // its credential can act here, but work assigned to the automation is work nobody
        // does (t_ca323f91).
        $workspaceMemberIds = User::query()
            ->memberOf($workspace)
            ->withoutPlatformServiceUser()
            ->pluck('id')
            ->all();

        return [
            'title' => ['required', 'string', 'max:255'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForWorkspace('companies', 'company_ids', $workspaceId)],
            'people_ids' => ['nullable', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForWorkspace('people', 'people_ids', $workspaceId)],
            'opportunity_ids' => ['nullable', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForWorkspace('opportunities', 'opportunity_ids', $workspaceId)],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['string', Rule::in($workspaceMemberIds)],
        ];
    }
}
