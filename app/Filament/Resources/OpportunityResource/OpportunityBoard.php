<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Flowforge\Board as FlowforgeBoard;

/**
 * A board whose rows carry no position orders them by last update instead, and
 * a grouped count cannot carry that ORDER BY, so every count runs without it.
 */
final class OpportunityBoard extends FlowforgeBoard
{
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
