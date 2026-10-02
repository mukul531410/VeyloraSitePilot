<?php

namespace App\Services;

use App\Models\AutomationRule;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class ScheduledOccurrenceResolver
{
    /**
     * Resolve the stable key for one occurrence in the MVP UTC interval schedule.
     *
     * Supported schedule_json:
     * {"every_minutes": 5, "starts_at_utc": "2026-10-01T00:00:00Z"}
     */
    public function occurrenceKey(AutomationRule $rule, DateTimeInterface $scheduledAt): string
    {
        [$intervalMinutes, $startsAtUtc] = $this->validatedSchedule($rule);
        $occurrenceUtc = CarbonImmutable::instance($scheduledAt)->utc();
        if ((int) $occurrenceUtc->format('u') !== 0) {
            throw new InvalidArgumentException('Scheduled occurrences must have whole-second precision.');
        }

        $elapsedSeconds = $occurrenceUtc->getTimestamp() - $startsAtUtc->getTimestamp();
        if ($elapsedSeconds < 0
            || $elapsedSeconds % 60 !== 0
            || intdiv($elapsedSeconds, 60) % $intervalMinutes !== 0) {
            throw new InvalidArgumentException('Timestamp is not an occurrence in this automation schedule.');
        }

        return 'schedule:v1:'.$occurrenceUtc->format('Y-m-d\TH:i:s\Z');
    }

    public function latestDueOccurrence(AutomationRule $rule, DateTimeInterface $evaluatedAt): ?CarbonImmutable
    {
        [$intervalMinutes, $startsAtUtc] = $this->validatedSchedule($rule);
        $evaluationUtc = CarbonImmutable::instance($evaluatedAt)->utc();
        $elapsedSeconds = $evaluationUtc->getTimestamp() - $startsAtUtc->getTimestamp();
        if ($elapsedSeconds < 0) {
            return null;
        }

        $elapsedWholeMinutes = intdiv($elapsedSeconds, 60);
        $latestElapsedMinutes = intdiv($elapsedWholeMinutes, $intervalMinutes) * $intervalMinutes;

        return $startsAtUtc->addMinutes($latestElapsedMinutes);
    }

    /** @return array{0: int, 1: CarbonImmutable} */
    private function validatedSchedule(AutomationRule $rule): array
    {
        $schedule = $rule->schedule_json;
        if (! is_array($schedule)) {
            throw new InvalidArgumentException('Automation schedule must be an object.');
        }

        $keys = array_keys($schedule);
        sort($keys);
        if ($keys !== ['every_minutes', 'starts_at_utc']) {
            throw new InvalidArgumentException('Automation schedule must contain every_minutes and starts_at_utc only.');
        }

        $intervalMinutes = $schedule['every_minutes'];
        if (! is_int($intervalMinutes) || $intervalMinutes < 1) {
            throw new InvalidArgumentException('Automation every_minutes must be a positive integer.');
        }

        return [$intervalMinutes, $this->parseUtcStart($schedule['starts_at_utc'])];
    }

    private function parseUtcStart(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('starts_at_utc must be an RFC 3339 UTC timestamp.');
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        if ($start === false || $start->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('starts_at_utc must use YYYY-MM-DDTHH:MM:SSZ format.');
        }

        return $start;
    }
}
