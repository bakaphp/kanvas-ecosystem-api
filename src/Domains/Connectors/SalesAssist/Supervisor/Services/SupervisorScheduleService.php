<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Services;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Throwable;

/**
 * When conversations are reviewed: every hour inside work hours and every hour overnight, both in the
 * dealer's own time. A dealer without a timezone or work hours has no window to compute, so it keeps
 * the hourly configuration audit and the audit reports what is missing.
 */
final class SupervisorScheduleService
{
    public const string OVERNIGHT_WINDOW_SETTING = 'sa_supervisor_overnight_window';
    public const string DEFAULT_OVERNIGHT_WINDOW = '00:00-06:00';

    public function __construct(
        private readonly Companies $company,
    ) {
    }

    public function shouldReviewConversations(?Carbon $now = null): bool
    {
        $timezone = $this->company->getTimezone();

        if ($timezone === null || empty($this->company->get('work_hours'))) {
            return false;
        }

        return $this->isOvernight(($now ?? Carbon::now())->copy()->setTimezone($timezone)) || $this->isWorkHours();
    }

    public function isOvernight(Carbon $localNow): bool
    {
        $window = (string) ($this->company->get(self::OVERNIGHT_WINDOW_SETTING) ?: self::DEFAULT_OVERNIGHT_WINDOW);

        if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $window, $parts) !== 1) {
            [, $parts[1], $parts[2]] = [null, ...explode('-', self::DEFAULT_OVERNIGHT_WINDOW)];
        }

        $time = $localNow->format('H:i');

        return $parts[1] <= $parts[2]
            ? $time >= $parts[1] && $time < $parts[2]
            : $time >= $parts[1] || $time < $parts[2];
    }

    private function isWorkHours(): bool
    {
        try {
            return (new CompanyWorkHoursTool($this->company)->execute()['status'] ?? null) === 'work_hours';
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
