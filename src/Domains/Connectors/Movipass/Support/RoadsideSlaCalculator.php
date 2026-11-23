<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum as State;

/**
 * Spec v6.0 §14: how long each stage of a case took against its configured threshold. Everything is
 * read from the case's state history, so a metric is as reliable as the transitions that wrote it.
 * A stage still running counts against the clock up to now, which is what lets the cabin see a
 * breach before the stage ends.
 */
final class RoadsideSlaCalculator
{
    public const DEFAULT_MINUTES = [
        'dispatch' => 2,
        'cabin_review' => 10,
        'acceptance' => 5,
        'reassignment' => 10,
        'arrival' => 45,
        'resolution' => 90,
        'incident' => 30,
    ];

    /**
     * metric => [start state, end states]; the end is the first of them entered after the start.
     */
    private const STAGES = [
        'dispatch' => [State::CREATED, [State::PENDING_ASSIGNMENT, State::CABIN_REVIEW]],
        'cabin_review' => [State::CABIN_REVIEW, [State::PENDING_ASSIGNMENT, State::TECHNICIAN_ASSIGNED, State::CANCELLED, State::NOT_RESOLVED]],
        'acceptance' => [State::PENDING_ASSIGNMENT, [State::TECHNICIAN_ASSIGNED]],
        'reassignment' => [State::REASSIGNMENT, [State::TECHNICIAN_ASSIGNED]],
        'arrival' => [State::TECHNICIAN_ASSIGNED, [State::IN_PROGRESS]],
        'resolution' => [State::IN_PROGRESS, [State::COMPLETED, State::NOT_RESOLVED]],
        'incident' => [State::INCIDENT, [State::REASSIGNMENT, State::PENDING_ASSIGNMENT, State::NOT_RESOLVED, State::CANCELLED]],
    ];

    /**
     * @param array<string, int|float> $thresholds minutes per metric, defaults filled in
     * @return array<int, array<string, mixed>>
     */
    public static function forCase(array $assistanceCase, array $thresholds = [], ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $history = array_values(array_filter((array) ($assistanceCase['state_history'] ?? []), 'is_array'));
        $thresholds = [...self::DEFAULT_MINUTES, ...array_filter($thresholds, 'is_numeric')];
        $metrics = [];

        foreach (self::STAGES as $metric => [$start, $ends]) {
            $startIndex = self::firstEntry($history, [$start], 0);

            if ($startIndex === null) {
                continue;
            }

            // A case that closed without reaching the stage's end stops that clock where it closed.
            $endIndex = self::firstEntry($history, $ends, $startIndex + 1)
                ?? self::firstEntry($history, [State::COMPLETED, State::NOT_RESOLVED, State::CANCELLED], $startIndex + 1);
            $startedAt = Carbon::parse($history[$startIndex]['at']);
            $endedAt = $endIndex !== null ? Carbon::parse($history[$endIndex]['at']) : null;
            $minutes = round($startedAt->diffInSeconds($endedAt ?? $now) / 60, 1);
            $threshold = (float) $thresholds[$metric];

            $metrics[] = [
                'metric' => $metric,
                'started_at' => $startedAt->toISOString(),
                'ended_at' => $endedAt?->toISOString(),
                'minutes' => $minutes,
                'threshold_minutes' => $threshold,
                'running' => $endedAt === null,
                'breached' => $minutes > $threshold,
            ];
        }

        return $metrics;
    }

    /**
     * @param array<int, State> $states
     */
    private static function firstEntry(array $history, array $states, int $from): ?int
    {
        $values = array_map(static fn (State $state) => $state->value, $states);

        for ($i = $from, $count = count($history); $i < $count; $i++) {
            if (in_array($history[$i]['to'] ?? null, $values, true)) {
                return $i;
            }
        }

        return null;
    }
}
