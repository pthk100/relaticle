<?php

declare(strict_types=1);

namespace App\Support\LifecycleStage;

use Closure;

/**
 * The re-entrancy latch around a `lifecycle_stage` write (t_7273de85).
 *
 * Projecting a contact writes a `custom_field_values` row for the PERSON, and that
 * write re-enters `CustomFieldValueObserver`. That observer's own guard (an
 * opportunity-entity check) already stops the loop, but "already stopped" is an
 * argument about today's call graph: the latch makes it true by construction, so a
 * future observer added on the person side cannot turn one stage move into a
 * cascade.
 *
 * A plain class with a static depth, not a property on the projector: the observers
 * are `readonly` (PHP forbids static properties there), and the latch has to be
 * visible to every projector instance - each observer event resolves its own.
 *
 * Nesting is counted, not a bool: a project inside a project (a listener writing
 * another stage) decrements correctly instead of clearing the flag early.
 */
final class LifecycleStageProjection
{
    private static int $depth = 0;

    public static function depth(): int
    {
        return self::$depth;
    }

    public static function isRunning(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public static function run(Closure $write): mixed
    {
        self::$depth++;

        try {
            return $write();
        } finally {
            self::$depth--;
        }
    }
}
