<?php

declare(strict_types=1);

namespace App\Support\ConsentMirror;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomFieldOption;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Models\CustomField;

/**
 * THE READ-ONLY GUARD for the five consent mirror fields (t_a8dd1bae).
 *
 * ── Why this exists at all ──────────────────────────────────────────────────
 * `marketing_optin`, `dnc`, `optout_reason`, `jurisdiction` and `lawful_basis` are
 * a PROJECTION of the platform's own consent store. The send gate
 * (`orchestrator/src/consent/eligible.ts`) reads that store and never this CRM, so
 * the copy is a warning surface: it must never say `granted` for somebody who
 * withdrew. `system_defined` is only a flag on the row — it makes the field
 * undeletable and nothing else — so the invariant needs a real guard, and this is
 * the smallest one that holds: the value write itself is refused, so it does not
 * matter whether the caller is the web form, the REST API, the MCP tools or an
 * import.
 *
 * ── The two behaviours, and why they are different ──────────────────────────
 * `UsesCustomFields::saveCustomFields()` writes NULL for every code the payload
 * does not mention, so an ordinary contact edit ("change the job title") reaches
 * this guard holding null for all five mirrors. Treating that as an edit would
 * make every contact save a 422. So:
 *
 *   value is null, or equals what is stored   -> not an edit: the write is skipped
 *   value differs from what is stored         -> REFUSED, 422, naming the field
 *
 * ── The one caller that may write them ──────────────────────────────────────
 * The platform, through `POST /api/v1/platform/consent-mirror`, which is guarded by
 * the `X-Platform-Key` shared secret (`EnsurePlatformKey`) rather than a tenant
 * credential. `runAsPlatform()` is the only way to set that mode and it is scoped
 * to a closure, so a leaked flag cannot survive the request.
 */
final class ConsentMirrorGuard
{
    /**
     * Nesting depth of `runAsPlatform()`. An int, not a bool, so a platform write
     * that itself triggers an update cannot end the mode early.
     */
    private static int $platformDepth = 0;

    /** @return list<string> */
    public static function protectedCodes(): array
    {
        return PeopleField::consentMirrorCodes();
    }

    public static function isProtected(CustomField $field): bool
    {
        return in_array((string) $field->code, self::protectedCodes(), true);
    }

    public static function isPlatformWrite(): bool
    {
        return self::$platformDepth > 0;
    }

    /**
     * Run a write with the guard open. The flag is restored in a finally, so an
     * exception inside the write cannot leave the CRM accepting tenant writes to
     * the mirrors for the rest of the request.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public static function runAsPlatform(Closure $write): mixed
    {
        self::$platformDepth++;

        try {
            return $write();
        } finally {
            self::$platformDepth--;
        }
    }

    /**
     * Refuse a tenant write that would CHANGE a mirror field.
     *
     * @throws ValidationException on a real change
     */
    public static function assertNotAHumanEdit(CustomField $field, mixed $incoming, mixed $current): void
    {
        if (! self::isProtected($field) || self::isPlatformWrite()) {
            return;
        }

        // Absent from the payload — `saveCustomFields()` nulls what it cannot find.
        if ($incoming === null) {
            return;
        }

        if (self::normalise($incoming) === self::normalise($current)) {
            return;
        }

        // A select's value has two spellings — the option ID the API normalises to
        // and the label the field shows — so raw equality is not enough: re-saving
        // the SAME option must not read as a change.
        if (self::sameOptionValue($field, $incoming, $current)) {
            return;
        }

        throw ValidationException::withMessages([
            'custom_fields.'.$field->code => sprintf(
                '%s is written by the platform consent store and is read-only here. '
                .'Change the contact\'s consent in the platform; the field will follow.',
                (string) $field->name,
            ),
        ]);
    }

    /**
     * Are these the same OPTION of a choice field? Resolves both sides through the
     * field's own options (id or label), so `{option-id}` and `{label}` compare
     * equal. Non-choice fields fall through to the raw comparison already made.
     */
    private static function sameOptionValue(CustomField $field, mixed $incoming, mixed $current): bool
    {
        if ($incoming === null || $current === null) {
            return false;
        }

        $left = self::normalise($incoming);
        $right = self::normalise($current);

        if ($left === null || $right === null) {
            return false;
        }

        return (self::optionName($field, $left) ?? $left) === (self::optionName($field, $right) ?? $right);
    }

    /** The option's name, given either its id or its name; null when it is neither. */
    private static function optionName(CustomField $field, string $value): ?string
    {
        $option = CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $field->getKey())
            ->where(fn ($query) => $query->where('id', $value)->orWhere('name', $value))
            ->first();

        return $option?->name;
    }

    /**
     * A comparable form of a stored/offered value. Values arrive as strings
     * (selects), booleans (toggles) and nulls, and the stored form is the typed
     * column, so both sides are flattened to a string or null before comparing.
     * Deliberately not `==`: `0 == null` and `false == ''` would make a real change
     * look like a no-op.
     */
    public static function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || $value instanceof Collection) {
            $parts = is_array($value) ? $value : $value->all();
            $flat = array_map(static fn (mixed $part): ?string => self::normalise($part), $parts);
            sort($flat);

            return implode('|', array_map(static fn (?string $part): string => (string) $part, $flat));
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return trim((string) $value);
    }
}
