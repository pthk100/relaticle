<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LifecycleStage\LifecycleStageReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The `lifecycle_stage` reconciliation pass (t_7273de85).
 *
 * Two modes, both idempotent, both named in the schedule entry in
 * `bootstrap/app.php`:
 *
 *   --incremental  the five-minute tick; reads contacts whose opportunities or
 *                  stage values moved since the cursor (`platform_reconcile_cursors`,
 *                  name `lifecycle_stage`, minus a ten-minute overlap)
 *   --full         the nightly sweep; every contact in every workspace, which is
 *                  the only thing that catches a hard delete or a value row written
 *                  behind the application's back
 *
 * Its output is the operator's evidence: how many contacts it read, how many it
 * had to CHANGE. A sweep that changes something is not a problem - it is the
 * field being corrected, which is exactly what it exists for.
 */
#[Description('Recompute the derived lifecycle_stage of every contact, or of those touched since the last pass')]
#[Signature('platform:reconcile-lifecycle-stages
                            {--full : Sweep every contact in every workspace instead of using the cursor}
                            {--chunk=500 : Contacts read per database chunk in a full sweep}')]
final class ReconcileLifecycleStagesCommand extends Command
{
    public function handle(LifecycleStageReconciler $reconciler): int
    {
        $result = $this->option('full') === true
            ? $reconciler->full((int) $this->option('chunk'))
            : $reconciler->incremental();

        $this->info(sprintf(
            '%s pass: %d workspace(s), %d contact(s) read, %d corrected; cursor %s',
            $result['mode'],
            $result['workspaces'],
            $result['scanned'],
            $result['changed'],
            $result['cursor'] ?? 'none',
        ));

        return self::SUCCESS;
    }
}
