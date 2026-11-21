<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Response;

/**
 * @group Workspace members
 *
 * List the people who can be named as a workspace member, such as a task assignee or a
 * company's account owner. Each row carries the id a member field accepts.
 */
final readonly class WorkspaceMembersController
{
    #[Response(content: [
        'data' => [
            ['id' => '01jz5v6y8k4m2n0p3q6r9s1t4u', 'name' => 'Mint Suwan', 'email' => 'mint@example.com', 'role' => 'owner'],
            ['id' => '01jz5v6y8k4m2n0p3q6r9s1t4v', 'name' => 'Ada Member', 'email' => 'ada@example.com', 'role' => 'member'],
        ],
    ])]
    public function index(string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $workspace = $user->currentWorkspace;

        if (! $workspace instanceof Workspace || $workspace->slug !== $slug) {
            return response()->json(['message' => 'You do not belong to this workspace.'], 403);
        }

        return response()->json(['data' => $this->membersOf($workspace)]);
    }

    /**
     * @return list<array{id: string, name: string, email: string, role: string}>
     */
    private function membersOf(Workspace $workspace): array
    {
        $owner = $workspace->owner;

        // The owner is listed from the workspace's own column, never from a pivot row, so a
        // workspace whose owner is not attached to `workspace_user` still lists them.
        $ownerRow = $owner instanceof User ? [$this->row($owner, 'owner')] : [];

        $memberRows = $workspace->users()
            ->orderBy('users.name')
            ->get()
            ->reject(fn (User $member): bool => $owner instanceof User && $member->is($owner))
            ->map(fn (User $member): array => $this->row($member, (string) $member->membership->role))
            ->values()
            ->all();

        return [...$ownerRow, ...$memberRows];
    }

    /**
     * @return array{id: string, name: string, email: string, role: string}
     */
    private function row(User $user, string $role): array
    {
        return [
            'id' => (string) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $role,
        ];
    }
}
