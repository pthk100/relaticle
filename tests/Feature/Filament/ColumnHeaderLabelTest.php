<?php

declare(strict_types=1);

use App\Filament\Components\Tables\ColumnHeaderLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Every list column header carries an icon beside its label, and the label used
 * to be a `truncate` span: an ellipsis-capable span reports almost no min-content
 * width, so the table sized the column to its CELLS and clipped the header by a
 * few pixels. Measured on the authenticated opportunities list at :8900, six of
 * thirteen headers were clipped (KOC Stage 73px of text in 69px, Operator 59/55,
 * Sample Tracking 112/108, Stage 39/35, Close Date 73/69, Amount 52/48).
 *
 * This pins the markup the measurement depends on. The behavioural proof is the
 * browser measurement quoted on the card; jsdom cannot measure text clipping.
 */
it('sizes a header label by its own text instead of clipping it', function (): void {
    $label = ColumnHeaderLabel::make('KOC stage', Heroicon::OutlinedSquares2x2)->toHtml();

    expect($label)
        ->toContain('KOC stage')
        ->toContain('whitespace-nowrap')
        ->not->toContain('truncate');
});

it('escapes the label before it joins the icon markup', function (): void {
    expect(ColumnHeaderLabel::make('<b>Stage</b>', Heroicon::OutlinedSquares2x2)->toHtml())
        ->toContain('&lt;b&gt;Stage&lt;/b&gt;')
        ->not->toContain('<b>Stage</b>');
});
