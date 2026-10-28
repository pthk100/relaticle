<?php

declare(strict_types=1);

use App\Onboarding\PlatformContactFieldSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the platform contact field set (PeopleField, 25 codes) into every
 * workspace that already exists.
 *
 * New workspaces get the same set from CreateWorkspaceCustomFields, so this
 * migration covers only the workspaces that predate a code. It is idempotent:
 * PlatformContactFieldSet skips codes that are already present and only rewrites
 * a stored setting when it differs from the declaration, so a second run inserts
 * zero rows and writes zero settings.
 *
 * Safe to run on an empty database (a fresh install has no workspaces yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = PlatformContactFieldSet::applyToEveryTenant();

        Log::info('Seeded the platform contact field set.', $result);
    }

    /**
     * Data-only backfill. The fields are the platform contract — tenancy, the
     * consent mirrors and the upsert match ladder all name these codes — so
     * rolling back the migration does not delete them: a workspace that lost
     * marketing_optin would silently stop mirroring consent. Removing a code
     * from the set is a product decision, made by editing PeopleField.
     */
    public function down(): void {}
};
