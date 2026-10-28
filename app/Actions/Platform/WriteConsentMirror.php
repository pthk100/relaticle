<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\People;
use App\Models\Workspace;
use App\Support\ConsentMirror\ConsentMirrorGuard;
use App\Support\CurrentWorkspace;
use App\Support\CustomFieldMerger;
use Illuminate\Validation\ValidationException;

/**
 * THE PLATFORM'S WRITE into the consent mirror (t_a8dd1bae).
 *
 * The five mirror fields are read-only for every tenant-authenticated caller
 * (`ConsentMirrorGuard`), so the platform's projection needs its own door: this
 * action, reached only from `POST /api/v1/platform/consent-mirror`, which
 * `EnsurePlatformKey` guards with the `X-Platform-Key` shared secret — the same
 * seam that creates workspaces (`relaticle-provisioning-seam.md`).
 *
 * ── Why it is all-or-nothing ────────────────────────────────────────────────
 * The five codes describe ONE consent state. A contact that carries
 * `marketing_optin=declined` from one projection and `dnc=false` from another is
 * worse than a contact with no mirror at all, because it reads as authoritative.
 * So every value is validated first (a code the select does not offer is a 422,
 * never a silent drop), then all five are written in one transaction.
 *
 * ── Why the merge ───────────────────────────────────────────────────────────
 * `saveCustomFields()` nulls every field the payload omits, so a bare write of
 * five codes would erase the other twenty contact fields. `CustomFieldMerger`
 * exists for exactly that (the CRM update path uses it); the same merge is used
 * here.
 */
final readonly class WriteConsentMirror
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    /**
     * @param  array<string, mixed>  $values  code => value (any subset of the five)
     * @return array<string, mixed> the five codes as stored after the write
     */
    public function execute(string $workspaceId, string $personId, array $values): array
    {
        $unknown = array_diff(array_keys($values), ConsentMirrorGuard::protectedCodes());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'values' => sprintf(
                    'Not a consent mirror field: %s. This endpoint writes only %s.',
                    implode(', ', $unknown),
                    implode(', ', ConsentMirrorGuard::protectedCodes()),
                ),
            ]);
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace) {
            throw ValidationException::withMessages(['workspace' => 'Unknown workspace.']);
        }

        // Binds BOTH contexts the write needs: the CRM models' WorkspaceScope and
        // the custom-fields TenantScope. Neither is set on a platform-key request,
        // and without them `customFields()` resolves to nothing — a write that
        // silently does nothing is the failure mode this seam must not have.
        return $this->currentWorkspace->within($workspace, function () use ($workspaceId, $personId, $values): array {
            $person = People::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($personId)
                ->first();

            if (! $person instanceof People) {
                throw ValidationException::withMessages(['person' => 'Unknown person in this workspace.']);
            }

            $fields = $this->fieldsByCode($workspaceId);

            foreach ($values as $code => $value) {
                $field = $fields[$code] ?? null;

                if (! $field instanceof CustomField) {
                    // The workspace never received the field set. Refuse loudly:
                    // the orchestrator will report it, and a contact quietly left
                    // without a mirror is the state this card exists to avoid.
                    throw ValidationException::withMessages([
                        'values' => sprintf('Workspace has no %s field.', $code),
                    ]);
                }

                $this->assertOffered($field, $workspaceId, $value);
            }

            return ConsentMirrorGuard::runAsPlatform(function () use ($person, $values): array {
                $person->update(CustomFieldMerger::merge($person, ['custom_fields' => $values]));

                // Read the five codes back from the row, so the response says what
                // the CONTACT now holds rather than what we meant to write.
                $fresh = $person->fresh();
                $fresh?->loadMissing('customFieldValues.customField');

                $stored = [];

                foreach (array_keys($values) as $code) {
                    $stored[$code] = null;
                }

                foreach ($fresh?->customFieldValues ?? [] as $valueRow) {
                    $code = (string) $valueRow->customField?->code;

                    if ($code !== '' && array_key_exists($code, $stored)) {
                        $stored[$code] = $valueRow->getValue();
                    }
                }

                return $stored;
            });
        });
    }

    /**
     * @return array<string, CustomField>
     */
    private function fieldsByCode(string $workspaceId): array
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', 'people')
            ->whereIn('code', ConsentMirrorGuard::protectedCodes())
            ->get()
            ->keyBy(fn (CustomField $field): string => (string) $field->code)
            ->all();
    }

    /**
     * A select value must be one of the options the workspace holds; a toggle must
     * be a boolean. `null` is allowed for every code — it means "the platform has
     * no value", and the field is emptied rather than left stale.
     */
    private function assertOffered(CustomField $field, string $workspaceId, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if ($field->type === 'toggle') {
            if (! is_bool($value)) {
                throw ValidationException::withMessages([
                    'values.'.$field->code => 'Must be a boolean.',
                ]);
            }

            return;
        }

        $offered = CustomFieldOption::query()
            ->withoutGlobalScopes()
            ->where('custom_field_id', $field->getKey())
            ->where('tenant_id', $workspaceId)
            ->pluck('name')
            ->all();

        if (! is_string($value) || ! in_array($value, $offered, true)) {
            throw ValidationException::withMessages([
                'values.'.$field->code => sprintf(
                    'Must be one of: %s.',
                    implode(', ', $offered),
                ),
            ]);
        }
    }
}
