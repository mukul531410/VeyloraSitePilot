<?php

namespace App\Services;

use App\Models\AutomationRule;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class AutomationScheduleResolver
{
    public function __construct(private ScheduledOccurrenceResolver $occurrences) {}

    /**
     * @return array{scheduled_at: CarbonImmutable, occurrence_key: string}|null
     */
    public function resolve(AutomationRule $rule, DateTimeInterface $evaluatedAt): ?array
    {
        $scheduledAt = $this->occurrences->latestDueOccurrence($rule, $evaluatedAt);
        if ($scheduledAt === null) {
            return null;
        }

        return [
            'scheduled_at' => $scheduledAt,
            'occurrence_key' => $this->occurrences->occurrenceKey($rule, $scheduledAt),
        ];
    }
}
