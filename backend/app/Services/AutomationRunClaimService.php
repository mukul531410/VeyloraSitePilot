<?php

namespace App\Services;

use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;

class AutomationRunClaimService
{
    /** Returns the claimed run, or null when it is no longer claimable. */
    public function claim(AutomationRun|string $run): ?AutomationRun
    {
        $runId = $run instanceof AutomationRun ? $run->getKey() : $run;

        return DB::transaction(function () use ($runId): ?AutomationRun {
            $lockedRun = AutomationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== AutomationRun::STATUS_PENDING) {
                return null;
            }

            $lockedRun->transitionToEvaluating();
            $lockedRun->save();

            return $lockedRun->fresh();
        });
    }
}
