<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Enums\MediaCollection;
use App\Models\Concerns\BelongsToWorkspaceCreator;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasNotes;
use App\Models\Concerns\HasWorkspace;
use App\Models\Concerns\LogsRelationChanges;
use App\Models\Pivots\Taskable;
use App\Models\Scopes\WorkspaceScope;
use App\Observers\PeopleObserver;
use App\Services\AvatarService;
use App\Support\ConsentMirror\ConsentMirrorGuard;
use App\Support\Media\UploadAllowlist;
use Carbon\CarbonImmutable;
use Database\Factories\PeopleFactory;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\EmailIntegration\Models\Concerns\HasEmails;
use Relaticle\EmailIntegration\Models\Concerns\HasMeetings;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property CarbonImmutable|null $deleted_at
 * @property CreationSource $creation_source
 * @property CarbonImmutable|null $last_email_at
 * @property CarbonImmutable|null $last_interaction_at
 * @property CarbonImmutable|null $last_meeting_at
 * @property int $email_count
 * @property int $inbound_email_count
 * @property int $outbound_email_count
 * @property int $meeting_count
 */
#[ObservedBy(PeopleObserver::class)]
#[ScopedBy(WorkspaceScope::class)]
#[Fillable([
    'name',
    'creation_source',
])]
final class People extends Model implements HasAvatar, HasCustomFields, HasMedia, HasTimeline
{
    use BelongsToWorkspaceCreator;
    use HasActivityTimeline;
    use HasCreator;
    use HasEmails;

    /** @use HasFactory<PeopleFactory> */
    use HasFactory;

    use HasMeetings;
    use HasNotes;
    use HasUlids;
    use HasWorkspace;
    use InteractsWithMedia;
    use LogsActivity;
    use LogsRelationChanges;
    use SoftDeletes;
    use UsesCustomFields {
        // The guard below wraps this, so the trait's write needs its own name.
        saveCustomFieldValue as private traitSaveCustomFieldValue;
        saveCustomFields as private traitSaveCustomFields;
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'creation_source' => CreationSource::class,
            'last_email_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'last_meeting_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): string => resolve(AvatarService::class)->generateAuto(name: $this->name, initialCount: 1));
    }

    /**
     * THE CONSENT MIRROR IS READ-ONLY FOR HUMANS (t_a8dd1bae).
     *
     * Every custom-field write funnels through this one trait method — the web
     * form, the REST API, the MCP tools and the importers all end up here — so this
     * is the smallest place that can hold the invariant: `marketing_optin` and its
     * four siblings are a projection of the platform's consent store, and a contact
     * that says `granted` for somebody who withdrew is the failure the card exists
     * to prevent.
     *
     * A null is NOT an edit: `saveCustomFields()` writes null for every code the
     * payload does not mention, so skipping it keeps the stored value and leaves
     * ordinary contact edits working. A DIFFERENT value is refused with a 422.
     * The platform's own platform-key-guarded endpoint writes them by wrapping the
     * call in `ConsentMirrorGuard::runAsPlatform()`.
     */
    public function saveCustomFieldValue(BaseCustomField $customField, mixed $value, ?Model $tenant = null): void
    {
        if (ConsentMirrorGuard::isProtected($customField) && ! ConsentMirrorGuard::isPlatformWrite()) {
            ConsentMirrorGuard::assertNotAHumanEdit($customField, $value, $this->storedCustomFieldValue($customField));

            if ($value === null) {
                return;
            }
        }

        $this->traitSaveCustomFieldValue($customField, $value, $tenant);
    }

    /**
     * What the contact holds for one field right now, in `getValue()`'s typed form.
     */
    private function storedCustomFieldValue(BaseCustomField $customField): mixed
    {
        $this->loadMissing('customFieldValues');

        $stored = $this->getRelation('customFieldValues')
            ->first(fn (CustomFieldValue $value): bool => (string) $value->custom_field_id === (string) $customField->getKey());

        return $stored?->getValue();
    }

    /**
     * The trait's `saveCustomFields()`, with its static payload cleaned up on a
     * refusal.
     *
     * `UsesCustomFields` stashes the payload in `static::$tempCustomFields` keyed by
     * `spl_object_id($this)` and only unsets it AFTER a successful write. A guard
     * refusal throws in the middle of that loop, which would leave the payload in a
     * STATIC array — and `spl_object_id` is reused, so the next model to land on that
     * id would silently inherit somebody else's `custom_fields` (a live PHP-FPM
     * worker keeps statics across requests). That is the same class of defect the
     * guard exists to prevent, so the cleanup is part of the guard, not an
     * afterthought: a refused write must not become another record's write.
     *
     * @param  array<string, mixed>  $customFields
     */
    public function saveCustomFields(array $customFields, ?Model $tenant = null): void
    {
        try {
            $this->traitSaveCustomFields($customFields, $tenant);
        } catch (\Throwable $exception) {
            unset(static::$tempCustomFields[spl_object_id($this)]);

            throw $exception;
        }
    }

    public function getFilamentAvatarUrl(): string
    {
        return $this->avatar;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return MorphToMany<Task, $this, Taskable>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'taskable')->using(Taskable::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollection::Attachments->value)
            ->acceptsMimeTypes(UploadAllowlist::mimeTypes());
    }

    /**
     * @return array<string, string>
     */
    public function relationChangeLabels(): array
    {
        return [
            'company_id' => __('filament/resources/person.fields.company_id.label'),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->logExcept([
                'id', 'workspace_id', 'creator_id', 'creation_source', 'custom_fields',
                'created_at', 'updated_at', 'deleted_at',
                'last_email_at', 'last_interaction_at', 'email_count', 'inbound_email_count',
                'outbound_email_count', 'meeting_count', 'last_meeting_at', 'company_id',
            ])
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
