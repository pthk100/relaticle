<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\CreationSource;
use App\Models\User;
use App\Support\CurrentSource;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $created_by
 */
trait HasCreator
{
    public function initializeHasCreator(): void
    {
        if ($this->exists) {
            return;
        }

        $this->attributes['creation_source'] ??= CurrentSource::get()->value;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Determine if the system created the record.
     *
     * The platform's own service identity counts as the system even though it reaches us over
     * the REST API: a projected row does have an author, and that author is not a colleague.
     */
    public function isSystemCreated(): bool
    {
        return $this->creatorIsPlatformServiceUser()
            || in_array($this->creation_source, CreationSource::automated(), true);
    }

    /**
     * Whether the platform wrote this record, through the identity it signs as.
     */
    public function creatorIsPlatformServiceUser(): bool
    {
        $creator = $this->creator;

        return $creator instanceof User && $creator->isPlatformServiceUser();
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function ownData(Builder $query): void
    {
        $query->whereNotIn($query->qualifyColumn('creation_source'), CreationSource::automated());
    }

    /**
     * @return Attribute<string, never>
     */
    protected function createdBy(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->creatorIsPlatformServiceUser() => '⊙ '.User::PLATFORM_SERVICE_USER_LABEL,
                $this->isSystemCreated() => "⊙ {$this->creation_source->getLabel()}",
                // @phpstan-ignore nullsafe.neverNull (creator_id can reference a deleted user)
                default => $this->creator?->name ?? 'Former Member',
            },
        );
    }
}
