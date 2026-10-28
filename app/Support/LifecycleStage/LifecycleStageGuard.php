<?php

declare(strict_types=1);

namespace App\Support\LifecycleStage;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomFieldOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * THE READ-ONLY GUARD for `lifecycle_stage` (t_7273de85).
 *
 * ── Why a guard and not a field setting ─────────────────────────────────────
 * The fork has no read-only flag on a custom field: `system_defined` stops a field
 * being DELETED and nothing else, and the settings vocabulary has no `read_only`
 * key. So "a hand edit must not survive" needs code, and the single funnel every
 * custom-field write passes through is `People::saveCustomFieldValue()` - the web
 * form, the REST API, the MCP tools and the importers all reach it.
 *
 * ── The two behaviours ──────────────────────────────────────────────────────
 * `saveCustomFields()` writes NULL for every code the payload omits, so an
 * ordinary contact edit ("change the job title") arrives here holding null for
 * `lifecycle_stage`. Treating that as an edit would 422 every contact save. So:
 *
 *   value is null, or equals what is stored  -> not an edit: the write is skipped
 *   value differs                            -> REFUSED, 422, naming the field
 *
 * ── There is NO permitted writer in the model layer ──────────────────────────
 * Deliberately, and it is what makes this guard airtight rather than a convention:
 * the projection does not go through `saveCustomFields()` at all, it writes the one
 * `custom_field_values` row it owns (`LifecycleStageProjector::store`). So there is
 * no flag to set, no closure to wrap and no state to leak: every write that reaches
 * this funnel - the web form, the REST API, the MCP tools, the importers - is a
 * human edit, and every human edit is refused.
 *
 * ── And the belt to that braces ─────────────────────────────────────────────
 * A guard refuses an API call; it cannot refuse a psql prompt, a restored backup
 * or a bug that writes the value row directly. That is why the field also has a
 * reconciliation pass that OVERWRITES any value that disagrees with the deals
 * (`LifecycleStageReconciler`) - the guard is the polite refusal, the sweep is the
 * correction. The card requires both.
 */
final class LifecycleStageGuard
{
    /** @return list<string> */
    public static function protectedCodes(): array
    {
        return [PeopleField::LIFECYCLE_STAGE->value];
    }

    public static function isProtected(Model $field): bool
    {
        return in_array((string) $field->getAttribute('code'), self::protectedCodes(), true);
    }

    /**
     * Refuse a tenant write that would CHANGE the derived stage.
     *
     * @throws ValidationException on a real change
     */
    public static function assertNotAHumanEdit(Model $field, mixed $incoming, mixed $current): void
    {
        if (! self::isProtected($field)) {
            return;
        }

        // Absent from the payload - `saveCustomFields()` nulls what it cannot find.
        if ($incoming === null) {
            return;
        }

        if (self::normalise($incoming) === self::normalise($current)) {
            return;
        }

        // A select's value has two spellings - the option id the API normalises to
        // and the option name the field shows - so raw equality is not enough:
        // re-saving the SAME option must not read as a change.
        if (self::sameOption($field, $incoming, $current)) {
            return;
        }

        throw ValidationException::withMessages([
            'custom_fields.'.$field->getAttribute('code') => sprintf(
                '%s is derived from this contact\'s opportunities and is read-only. '
                .'Move the deal instead: the field follows.',
                (string) $field->getAttribute('name'),
            ),
        ]);
    }

    /**
     * Are these the same OPTION of the stage select? Resolves both sides through
     * the field's own options (id or name), so `{option-id}` and `{name}` compare
     * equal.
     */
    private static function sameOption(Model $field, mixed $incoming, mixed $current): bool
    {
        $left = self::normalise($incoming);
        $right = self::normalise($current);

        if ($left === null || $right === null) {
            return false;
        }

        return (self::optionName($field, $left) ?? $left) === (self::optionName($field, $right) ?? $right);
    }

    /** The option's name, given either its id or its name; null when it is neither. */
    private static function optionName(Model $field, string $value): ?string
    {
        $option = CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $field->getKey())
            ->where(fn ($query) => $query->where('id', $value)->orWhere('name', $value))
            ->first();

        return $option?->name;
    }

    /**
     * A comparable form of a stored/offered value: the option id and the option
     * name both turn into the NAME, and a typed boolean or date is flattened to a
     * string. Deliberately not `==` (`0 == null`, `false == ''`).
     */
    public static function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return trim((string) $value);
    }
}
