<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CompaniesController;
use App\Http\Controllers\Api\V1\CompaniesUpsertController;
use App\Http\Controllers\Api\V1\ConsentMirrorController;
use App\Http\Controllers\Api\V1\CustomFieldsController;
use App\Http\Controllers\Api\V1\NotesController;
use App\Http\Controllers\Api\V1\OpportunitiesController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\PeopleUpsertController;
use App\Http\Controllers\Api\V1\TasksController;
use App\Http\Controllers\Api\V1\WorkspaceDeprovisionController;
use App\Http\Controllers\Api\V1\WorkspaceProvisionController;
use App\Http\Controllers\Api\V1\WorkspaceSessionController;
use App\Http\Controllers\Api\V1\WorkspaceTokenController;
use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use App\Http\Middleware\EnsurePlatformKey;
use App\Http\Middleware\EnsureTokenHasAbility;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetApiWorkspaceContext;
use App\Http\Middleware\SetCurrentSource;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform provisioning  (fork addition — see NOTICE)
|--------------------------------------------------------------------------
|
| Deliberately OUTSIDE the workspace-scoped group below. Those routes require a
| Sanctum token pinned to a workspace (SetApiWorkspaceContext), and the entire point
| of this route is to create the workspace that such a token is pinned to — so no
| token can exist yet. It authenticates the calling platform with a shared secret
| instead of authenticating a tenant user.
|
| Reuses upstream's App\Actions\Jetstream\CreateWorkspace, so workspace creation
| keeps a single code path.
*/
Route::prefix('v1')
    ->middleware([ForceJsonResponse::class, EnsurePlatformKey::class, 'throttle:api'])
    ->group(function (): void {
        Route::post('workspaces', WorkspaceProvisionController::class)->name('platform.workspaces.store');
        // The console's session broker: mint a one-time login for a workspace's
        // tenant owner, so a brand that signed up once never meets the CRM's own
        // login (t_5df176cd). Same shared-secret guard, for the same reason — no
        // workspace-pinned token can exist for a session being established.
        Route::post('workspaces/{slug}/session', WorkspaceSessionController::class)->name('platform.workspaces.session');
        // Credential REISSUE for a workspace that already exists (t_659436f5). The
        // session route adopts the workspace onto the tenant's identity; the token
        // the orchestrator stores was minted for the PREVIOUS owner and cannot
        // survive that move (the plaintext lives with the caller; we hold only the
        // hash). This route mints the replacement and retires the old one so a
        // stored credential never outlives the authority it was minted under.
        Route::post('workspaces/{slug}/token', WorkspaceTokenController::class)->name('platform.workspaces.token');
        // The consent mirror's write path (t_a8dd1bae). The five mirror fields on
        // a contact are read-only for every tenant-authenticated caller, so the
        // orchestrator's projection needs this platform-guarded door: it states the
        // workspace and the person, and only the five codes are writable through it.
        Route::post('platform/consent-mirror', ConsentMirrorController::class)
            ->name('platform.consent-mirror.store');
        // Teardown counterpart — the orchestrator's cancel path deletes the tenant
        // by slug over the same platform-guarded group (a workspace-pinned token
        // cannot exist for a workspace being removed).
        Route::delete('workspaces/{slug}', WorkspaceDeprovisionController::class)->name('platform.workspaces.destroy');
    });

Route::prefix('v1')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum,api', 'throttle:api', EnsureTokenHasAbility::class, SetCurrentSource::class.':api', SetApiWorkspaceContext::class, EnsureHostedWorkspaceAccess::class])
    ->group(function (): void {
        Route::get('user', function (Request $request) {
            return new UserResource($request->user());
        });

        // Registered before the resource routes so `upsert` is never read as a record key.
        Route::post('companies/upsert', CompaniesUpsertController::class)
            ->middleware(EnsureTokenHasAbility::class.':create,update')
            ->name('companies.upsert');

        Route::post('people/upsert', PeopleUpsertController::class)
            ->middleware(EnsureTokenHasAbility::class.':create,update')
            ->name('people.upsert');

        foreach (['companies' => CompaniesController::class, 'people' => PeopleController::class, 'opportunities' => OpportunitiesController::class, 'tasks' => TasksController::class, 'notes' => NotesController::class] as $resource => $controller) {
            Route::post("{$resource}/query", [$controller, 'index'])->name("{$resource}.query");
            Route::apiResource($resource, $controller);
        }

        Route::get('custom-fields', [CustomFieldsController::class, 'index'])->name('custom-fields.index');
    });
