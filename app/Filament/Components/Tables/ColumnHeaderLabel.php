<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use BackedEnum;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

final readonly class ColumnHeaderLabel
{
    /**
     * The label is `whitespace-nowrap` rather than `truncate` on purpose: a
     * truncating span reports ~no min-content width, so the table sizes a column
     * to its cells and clips the header by a few pixels. Nowrap makes the label's
     * own width part of the column's min-content, which is what keeps "KOC stage"
     * whole at the default column width.
     */
    public static function make(string $label, string|BackedEnum $icon): HtmlString
    {
        $icon = generate_icon_html($icon, size: IconSize::Small)?->toHtml();
        $label = e($label);

        return new HtmlString(
            "<span class=\"fi-ta-header-cell-field inline-flex min-w-0 items-center gap-1.5 align-middle\">{$icon}<span class=\"whitespace-nowrap\">{$label}</span></span>"
        );
    }
}
