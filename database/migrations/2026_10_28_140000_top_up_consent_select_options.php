<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Onboarding\PlatformContactFieldSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tops up the OPTIONS of the platform's consent selects in every existing
 * workspace (t_a8dd1bae).
 *
 * `optout_reason` grew from six source-shaped codes (user_request,
 * unsubscribe_link, hard_bounce, complaint, admin, other) to a controlled
 * vocabulary that also carries the REASONS a person gives for leaving
 * (too_frequent, not_relevant, never_signed_up, competitor, no_longer_customer,
 * privacy_concern). A workspace seeded before this migration offers only the old
 * six, so a platform write of `too_frequent` would be refused by the CRM as a
 * value the select does not hold.
 *
 * Idempotent: `PlatformContactFieldSet` inserts only the options a field is
 * missing, and never deletes or renames one — a tenant's own curation of the
 * fields it is allowed to curate survives, and a second run inserts zero rows.
 *
 * Safe on an empty database (no workspaces, no rows).
 *
 * Forward only, like every migration here: dropping options that contacts already
 * point at would orphan their stored values, and the six new codes are additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = DB::table('custom_field_options')
            ->whereIn('name', PeopleField::OPTOUT_REASON_VALUES)
            ->count();

        $result = PlatformContactFieldSet::applyToEveryTenant();

        $after = DB::table('custom_field_options')
            ->whereIn('name', PeopleField::OPTOUT_REASON_VALUES)
            ->count();

        // The field-set applier reports FIELD rows inserted (0 on an already-seeded
        // fleet), so the option delta is measured here rather than inferred.
        Log::info('Topped up the platform consent select options.', [
            'workspaces' => $result['tenants'],
            'option_rows_before' => $before,
            'option_rows_after' => $after,
            'option_rows_added' => $after - $before,
        ]);
    }
};
