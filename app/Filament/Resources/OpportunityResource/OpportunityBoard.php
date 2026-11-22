<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource;

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Flowforge\Board as FlowforgeBoard;

/**
 * A board whose rows carry no position orders them by last update instead, and
 * a grouped count cannot carry that ORDER BY, so every count runs without it.
 *
 * It also holds the collaboration card's identity: a row's `name` is the
 * projection's composed search string (`{Brand} | {SKU} - @{handle}`,
 * `collaboration-projection.ts`) and is never parsed, so the two lines a card
 * leads with are read off the record. It lives here rather than on the page
 * because a public method on a Livewire component is a browser-callable surface
 * and this one takes a model (tests/Arch/ArchTest.php).
 */
final class OpportunityBoard extends FlowforgeBoard
{
    /** Whether these rows are collaborations, whose cards lead with handle and product. */
    private bool $collaborationCards = false;

    /** @var array<string, CustomField|null> */
    private array $entityFields = [];

    /**
     * A sales board's card title already carries the brand, so it keeps the
     * package's card. Only the ladder re-letters it.
     */
    public function collaborationCards(bool $ladder = true): static
    {
        $this->collaborationCards = $ladder;

        return $this;
    }

    /**
     * The two facts a collaboration card leads with, or null where the card keeps
     * the record's own title.
     *
     * Nothing here writes: the composed name stays on the card's `title` attribute,
     * so hover and copy still answer a search for it.
     *
     * @return array{handle: ?string, product: ?string}|null
     */
    public function cardIdentity(?Model $record): ?array
    {
        if (! $this->collaborationCards || ! $record instanceof Opportunity) {
            return null;
        }

        return [
            'handle' => $this->creatorHandle($record),
            'product' => $this->productLine($record),
        ];
    }

    /**
     * Who the card is about. The person's `tiktok_handle` mirror is the structured
     * answer and wins where a projection has written it; the person's name is what
     * the CRM holds either way, so it is the fallback rather than a blank line.
     */
    private function creatorHandle(Opportunity $record): ?string
    {
        $contact = $record->contact;

        if (! $contact instanceof People) {
            return null;
        }

        $field = $this->entityField(People::class, PeopleField::TIKTOK_HANDLE->value);
        $mirrored = $field instanceof CustomField
            ? $contact->customFieldValues->firstWhere('custom_field_id', $field->getKey())?->getValue()
            : null;

        $handle = is_string($mirrored) && trim($mirrored) !== ''
            ? trim($mirrored)
            : trim((string) $contact->name);

        return $handle === '' ? null : '@'.ltrim($handle, '@');
    }

    /**
     * The product side, brand first and joined with a middle dot. Either half may be
     * missing (a legacy line has no SKU), and the line then carries only the half it
     * has rather than a dangling separator.
     */
    private function productLine(Opportunity $record): ?string
    {
        $sku = $this->mirroredValue($record, Opportunity::class, OpportunityField::PRODUCT_SKU->value);
        $company = $record->company;

        $parts = array_values(array_filter(
            [$company instanceof Company ? trim((string) $company->name) : '', is_string($sku) ? trim($sku) : ''],
            fn (string $part): bool => $part !== '',
        ));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function mirroredValue(Opportunity $record, string $entity, string $code): mixed
    {
        $field = $this->entityField($entity, $code);

        return $field instanceof CustomField
            ? $record->customFieldValues->firstWhere('custom_field_id', $field->getKey())?->getValue()
            : null;
    }

    /**
     * One field lookup per entity per request: a card asks for these on every row,
     * and `once()` would answer a second code with the first code's field because it
     * memoises per call site rather than per argument.
     */
    private function entityField(string $entity, string $code): ?CustomField
    {
        $key = $entity.'::'.$code;

        if (! array_key_exists($key, $this->entityFields)) {
            $this->entityFields[$key] = CustomField::query()
                ->forEntity($entity)
                ->where('code', $code)
                ->first();
        }

        return $this->entityFields[$key];
    }

    /**
     * @return array<array-key, int>
     */
    public function getBatchedBoardRecordCounts(): array
    {
        return $this->groupedRecordCounts($this->countableQuery());
    }

    /**
     * The rows this board's filters hide, per column. A column header counts what
     * it shows, so an empty lane needs this to tell nothing from nothing shown.
     *
     * @return array<string, int>
     */
    public function hiddenRecordCounts(): array
    {
        $shown = $this->getBatchedBoardRecordCounts();
        $hidden = [];

        foreach ($this->groupedRecordCounts($this->getQuery()) as $columnId => $total) {
            $missing = $total - (int) ($shown[$columnId] ?? 0);

            if ($missing > 0) {
                $hidden[(string) $columnId] = $missing;
            }
        }

        return $hidden;
    }

    /**
     * @return Builder<Model>|null
     */
    private function countableQuery(): ?Builder
    {
        $query = $this->getQuery();

        if (! $query instanceof Builder) {
            return null;
        }

        $livewire = $this->getLivewire();

        if (! $livewire->getTable()->isFilterable() && ! $livewire->hasTableSearch()) {
            return $query;
        }

        return $livewire->getFilteredTableQuery();
    }

    /**
     * @param  Builder<Model>|null  $query
     * @return array<array-key, int>
     */
    private function groupedRecordCounts(?Builder $query): array
    {
        if (! $query instanceof Builder) {
            return [];
        }

        $attribute = (string) $this->getColumnIdentifierAttribute();

        return (clone $query)
            ->reorder()
            ->select($attribute)
            ->selectRaw('COUNT(*) as total')
            ->groupBy($attribute)
            ->pluck('total', $attribute)
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
