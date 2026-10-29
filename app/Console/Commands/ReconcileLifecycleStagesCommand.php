<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LifecycleStage\LifecycleStageReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The `lifecycle_stage` reconciliation pass (t_7273de85, t_885ca3bf).
 *
 * Two modes, both idempotent, both named in the schedule entry in
 * `bootstrap/app.php`, both the SAME comparison of stored answer against derived
 * answer for every contact of every live workspace:
 *
 *   --incremental  the five-minute tick; stops before `--budget` contacts and
 *                  reports how many workspaces it left for the next tick
 *   --full         the nightly sweep and the operator's on-demand pass; no budget
 *
 * Its output is the operator's evidence: how many contacts it COMPARED, how many
 * it had to CHANGE, and how many workspaces it did not reach. A sweep that
 * changes something is not a problem - it is the field being corrected, which is
 * exactly what it exists for. A sweep that reaches everything and changes nothing
 * is the field being TRUE.
 */
#[Description('Recompute the derived lifecycle_stage of every contact, and write only the ones that disagree')]
#[Signature('platform:reconcile-lifecycle-stages
                            {--full : Compare every contact in every workspace with no per-tick contact budget}
                            {--budget=5000 : Contacts the tick may compare before it defers the remaining workspaces}
                            {--chunk=500 : Contacts read per database chunk when the pass has corrections to write}')]
final class ReconcileLifecycleStagesCommand extends Command
{
    public function handle(LifecycleStageReconciler $reconciler): int
    {
        $chunk = (int) $this->option('chunk');

        $result = $this->option('full') === true
            ? $reconciler->full($chunk)
            : $reconciler->incremental((int) $this->option('budget'), $chunk);

        $this->info(sprintf(
            '%s pass: %d workspace(s), %d contact(s) compared, %d corrected, %d workspace(s) not visited; cursor %s',
            $result['mode'],
            $result['workspaces'],
            $result['compared'],
            $result['changed'],
            $result['deferred'],
            $result['cursor'] ?? 'none',
        ));

        return self::SUCCESS;
    }
}
