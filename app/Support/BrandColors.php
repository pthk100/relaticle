<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * Filament resolves colors per panel, so a view rendered outside panel
 * middleware falls back to its default amber unless the same palette is
 * registered globally. This is the single source both registrations read.
 */
final readonly class BrandColors
{
    /**
     * The platform's one brand colour (`design/DESIGN-daylight.md`, the Daylight
     * terracotta). 600/700/800 are that file's primary, primary-hover and
     * primary-pressed tokens; the rest of the ramp is derived from the same hue.
     *
     * @return array<int|string, string>
     */
    public static function primary(): array
    {
        $palette = array_replace(Color::generatePalette('#AD4E2E'), [
            600 => '#AD4E2E',
            700 => '#99452A',
            800 => '#7E3A22',
        ]);

        $palette['DEFAULT'] = '#AD4E2E';

        return $palette;
    }
}
