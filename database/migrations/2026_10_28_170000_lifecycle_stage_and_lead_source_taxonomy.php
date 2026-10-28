<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Onboarding\PlatformContactFieldSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Two field-level amendments to the platform contact set (t_7273de85).
 *
 * 1. `lead_source` loses its channel values and keeps ORIGIN only. The old list
 *    mixed two axes (LINE and WhatsApp are channels, and the channel is already
 *    `preferred_channel` plus the per-channel ids), which made "how many live-chat
 *    leads?" unanswerable without a union over four values. The new list is in
 *    PeopleField::LEAD_SOURCE_OPTIONS.
 *
 *    The rewrite is only safe because nothing has written the field yet. The
 *    migration CHECKS that (a value row pointing at an old channel option), and
 *    where one exists it MAPS it rather than deleting the option under it: the
 *    stored value becomes `live_chat` (the origin the channel was standing in for)
 *    and the channel is recorded where a channel belongs, in `preferred_channel`
 *    when the person has no channel there. Nothing is ever silently blanked.
 *
 * 2. `lifecycle_stage` is seeded into every workspace that holds the contact set,
 *    via the same PlatformContactFieldSet applier new workspaces use, so a
 *    workspace created tomorrow and one created last month cannot differ.
 *
 * The first derived value is NOT written here. The reconciliation pass does that,
 * and its incremental mode falls back to a full sweep when it has no cursor, which
 * after this migration is exactly once (see LifecycleStageReconciler).
 *
 * Forward only, like every migration here. A rollback would have to re-introduce
 * a taxonomy whose values are now origins, which would silently relabel every
 * contact's lead source.
 */
return new class extends Migration
{
    /**
     * The old channel-flavoured option -> the origin it meant, plus the channel to
     * record in `preferred_channel` when the person has none. An empty channel means
     * the option was not a channel at all (Referral, Event, ...), so only the name
     * changed.
     *
     * @var array<string, array{origin: string, channel: string|null}>
     */
    private const array OLD_TO_ORIGIN = [
        'LINE' => ['origin' => 'live_chat', 'channel' => 'LINE'],
        'WhatsApp' => ['origin' => 'live_chat', 'channel' => 'WhatsApp'],
        'Web widget' => ['origin' => 'live_chat', 'channel' => null],
        'Referral' => ['origin' => 'referral', 'channel' => null],
        'Event' => ['origin' => 'event', 'channel' => null],
        'Ads' => ['origin' => 'ads', 'channel' => null],
        'Import' => ['origin' => 'import', 'channel' => null],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('platform_reconcile_cursors')) {
            Schema::create('platform_reconcile_cursors', function (Blueprint $table): void {
                // One row per projection that needs a "since when" answer. Named, not
                // one table per job: a second projection is a row, not a migration.
                $table->string('name')->primary();
                $table->string('value');
                $table->timestamps();
            });
        }

        $rewritten = $this->rewriteLeadSourceOptions();

        $seeded = PlatformContactFieldSet::applyToEveryTenant();

        Log::info('lead_source taxonomy rewritten; lifecycle_stage seeded.', [
            'lead_source_values_rewritten' => $rewritten,
            'field_set' => $seeded,
        ]);
    }

    /**
     * Replace the seven old options with the eight origin ones, in every workspace
     * that carries the field.
     *
     * @return int how many stored person values had to be re-pointed
     */
    private function rewriteLeadSourceOptions(): int
    {
        $declared = array_keys(PeopleField::LEAD_SOURCE_OPTIONS);

        $fields = DB::table('custom_fields')
            ->where('entity_type', 'people')
            ->where('code', PeopleField::LEAD_SOURCE->value)
            ->get(['id', 'tenant_id']);

        $rewritten = 0;

        foreach ($fields as $field) {
            $options = DB::table('custom_field_options')
                ->where('custom_field_id', $field->id)
                ->get(['id', 'name']);

            $byName = [];

            foreach ($options as $option) {
                $byName[(string) $option->name] = (string) $option->id;
            }

            // 1. Re-point every stored value that names an option which is about to
            //    disappear. The value is the option ID, and the person's channel is
            //    filled in only when it is empty: this migration does not overwrite a
            //    channel a human (or a connector) already recorded.
            foreach (self::OLD_TO_ORIGIN as $old => $target) {
                $oldOptionId = $byName[$old] ?? null;

                if ($oldOptionId === null || in_array($old, $declared, true)) {
                    continue;
                }

                $valueRows = DB::table('custom_field_values')
                    ->where('custom_field_id', $field->id)
                    ->where('string_value', $oldOptionId)
                    ->get(['id', 'entity_id']);

                if ($valueRows->isEmpty()) {
                    continue;
                }

                $originOptionId = $this->ensureOption($field->id, (string) $field->tenant_id, $target['origin'], $declared);
                $byName[$target['origin']] = $originOptionId;

                DB::table('custom_field_values')
                    ->whereIn('id', $valueRows->pluck('id')->all())
                    ->update(['string_value' => $originOptionId]);

                $rewritten += $valueRows->count();

                if ($target['channel'] !== null) {
                    $this->fillMissingChannel((string) $field->tenant_id, $valueRows, $target['channel']);
                }

                Log::warning('lead_source value mapped off a channel option.', [
                    'tenant_id' => $field->tenant_id,
                    'from' => $old,
                    'to' => $target['origin'],
                    'rows' => $valueRows->count(),
                ]);
            }

            // 2. Offer exactly the declared origins, in declared order: anything the
            //    select still shows that is not declared (the seven channel-era
            //    values, or a tenant's own leftover) is removed now that no value
            //    points at it.
            foreach ($options as $option) {
                if (in_array((string) $option->name, $declared, true)) {
                    continue;
                }

                $stillUsed = DB::table('custom_field_values')
                    ->where('custom_field_id', $field->id)
                    ->where('string_value', (string) $option->id)
                    ->exists();

                if ($stillUsed) {
                    // Refuse rather than strand a value: this is a data fact the
                    // migration did not expect, so it is reported, not swallowed.
                    Log::error('lead_source option still in use; left in place.', [
                        'tenant_id' => $field->tenant_id,
                        'option' => $option->name,
                    ]);

                    continue;
                }

                DB::table('custom_field_options')->where('id', $option->id)->delete();
            }

            foreach ($declared as $position => $name) {
                $optionId = $this->ensureOption($field->id, (string) $field->tenant_id, $name, $declared, $position);

                // Declared order is the display order: the select must read Live chat,
                // Member sign-up, Web form, ... not in the order the rows happened to
                // be created. "Only when it differs", so a second run writes nothing.
                DB::table('custom_field_options')
                    ->where('id', $optionId)
                    ->where('sort_order', '!=', $position)
                    ->update(['sort_order' => $position, 'updated_at' => now()]);
            }
        }

        return $rewritten;
    }

    /**
     * Insert a declared option when the workspace does not have it, and put every
     * declared option at its declared position so the select reads in the order the
     * enum declares.
     *
     * @param  list<string>  $declared
     */
    private function ensureOption(string $fieldId, string $tenantId, string $name, array $declared, ?int $position = null): string
    {
        $existing = DB::table('custom_field_options')
            ->where('custom_field_id', $fieldId)
            ->where('name', $name)
            ->first(['id']);

        if ($existing !== null) {
            return (string) $existing->id;
        }

        $id = (new \App\Models\CustomFieldOption)->newUniqueId();

        $order = $position ?? array_search($name, $declared, true);
        $order = is_int($order) ? $order : 0;

        DB::table('custom_field_options')->insert([
            'id' => $id,
            'custom_field_id' => $fieldId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) $id;
    }

    /**
     * Record the channel the old option implied, on the people whose
     * `preferred_channel` is empty. A person who already has a channel keeps it:
     * the origin is being corrected here, not their preference.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $valueRows
     */
    private function fillMissingChannel(string $tenantId, $valueRows, string $channel): void
    {
        $preferred = DB::table('custom_fields')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', 'people')
            ->where('code', PeopleField::PREFERRED_CHANNEL->value)
            ->first(['id']);

        if ($preferred === null) {
            return;
        }

        $channelOptionId = DB::table('custom_field_options')
            ->where('custom_field_id', $preferred->id)
            ->where('name', $channel)
            ->value('id');

        if ($channelOptionId === null) {
            return;
        }

        $personIds = DB::table('custom_field_values')
            ->where('custom_field_id', $preferred->id)
            ->whereIn('entity_id', $valueRows->pluck('entity_id')->all())
            ->pluck('entity_id')
            ->all();

        $missing = DB::table('custom_field_values')
            ->where('custom_field_id', $preferred->id)
            ->whereIn('entity_id', $valueRows->pluck('entity_id')->all())
            ->where(fn ($query) => $query->whereNull('string_value')->orWhere('string_value', ''))
            ->pluck('id')
            ->all();

        if ($missing !== []) {
            DB::table('custom_field_values')
                ->whereIn('id', $missing)
                ->update(['string_value' => $channelOptionId]);

            return;
        }

        // No preferred_channel row at all for these people: create one, which is the
        // same row `saveCustomFields()` would have written.
        $withoutRow = array_values(array_diff(
            $valueRows->pluck('entity_id')->all(),
            $personIds,
        ));

        $rows = [];

        foreach ($withoutRow as $personId) {
            $rows[] = [
                'id' => (new \App\Models\CustomFieldValue)->newUniqueId(),
                'tenant_id' => $tenantId,
                'entity_type' => 'people',
                'entity_id' => $personId,
                'custom_field_id' => $preferred->id,
                'string_value' => $channelOptionId,
            ];
        }

        if ($rows !== []) {
            DB::table('custom_field_values')->insert($rows);
        }
    }
};
