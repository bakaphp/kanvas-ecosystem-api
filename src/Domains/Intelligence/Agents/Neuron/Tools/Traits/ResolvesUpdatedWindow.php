<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns "today" / "yesterday" / "last_7_days" / a YYYY-MM-DD date into a UTC half-open window.
 *
 * The boundaries must be computed in the company's timezone and only then converted: timestamps are
 * stored in UTC, so filtering on the UTC day for a UTC-4 tenant reports rows touched after 8pm local
 * as tomorrow's and folds yesterday evening's into today. Requires HasKanvasContext ($this->company).
 */
trait ResolvesUpdatedWindow
{
    private const PRESETS = ['today', 'yesterday', 'this_week', 'last_7_days', 'this_month', 'last_30_days'];

    /**
     * `to` is exclusive, so a caller can use `>= from` and `< to` without worrying about whether the
     * column carries sub-second precision.
     *
     * @return array{from: ?Carbon, to: ?Carbon, timezone: string, label: ?string, error: ?string}
     */
    protected function resolveUpdatedWindow(?string $since, ?string $until = null): array
    {
        $timezone = $this->companyTimezone();
        $since = strtolower(trim((string) $since));
        $until = trim((string) $until);

        $empty = ['from' => null, 'to' => null, 'timezone' => $timezone, 'label' => null, 'error' => null];

        if ($since === '' && $until === '') {
            return $empty;
        }

        $now = Carbon::now($timezone);
        $from = null;
        $to = null;

        if ($since !== '') {
            $preset = $this->presetWindow($since, $now);

            if ($preset !== null) {
                [$from, $to] = $preset;
            } else {
                $from = $this->parseDay($since, $timezone);

                if ($from === null) {
                    return [...$empty, 'error' => $this->dateError('updated_since', $since)];
                }
            }
        }

        if ($until !== '') {
            $day = $this->parseDay($until, $timezone);

            if ($day === null) {
                return [...$empty, 'error' => $this->dateError('updated_until', $until)];
            }

            $to = $day->copy()->addDay();
        }

        if ($from !== null && $to !== null && $from >= $to) {
            return [...$empty, 'error' => 'updated_since is after updated_until — nothing could match.'];
        }

        return [
            'from' => $from?->copy()->utc(),
            'to' => $to?->copy()->utc(),
            'timezone' => $timezone,
            'label' => $this->windowLabel($from, $to, $timezone),
            'error' => null,
        ];
    }

    /**
     * @return array{0: Carbon, 1: ?Carbon}|null
     */
    private function presetWindow(string $preset, Carbon $now): ?array
    {
        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), null],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), null],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), null],
            'this_month' => [$now->copy()->startOfMonth(), null],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), null],
            default => null,
        };
    }

    private function parseDay(string $value, string $timezone): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, $timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function dateError(string $property, string $value): string
    {
        return sprintf(
            '%s must be a YYYY-MM-DD date or one of %s — got "%s".',
            $property,
            implode(', ', self::PRESETS),
            $value,
        );
    }

    private function windowLabel(?Carbon $from, ?Carbon $to, string $timezone): string
    {
        return sprintf(
            '%s → %s (%s)',
            $from?->format('Y-m-d H:i') ?? 'any',
            $to?->copy()->subSecond()->format('Y-m-d H:i') ?? 'now',
            $timezone,
        );
    }

    /**
     * Via getTimezone(), which validates: the column is free-form, and an unusable string handed to
     * Carbon::now() throws rather than degrading to UTC.
     */
    protected function companyTimezone(): string
    {
        return $this->company->getTimezone()
            ?? (string) (config('app.timezone') ?? 'UTC');
    }
}
