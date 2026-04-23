<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Illuminate\Support\Carbon;
use Override;

class ProcessElevenLabsAgentDateWebhookJob extends ProcessElevenLabsWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $company = $this->receiver->company;
        $timezone = $company->get('timezone') ?? $company->timezone ?? 'UTC';

        $now = Carbon::now($timezone);

        return [
            'timezone' => $timezone,
            'current_date' => $now->toDateString(),
            'current_time' => $now->toTimeString(),
            'current_datetime' => $now->toDateTimeString(),
            'iso8601' => $now->toIso8601String(),
            'day' => $now->day,
            'day_of_week' => $now->dayName,
            'day_of_week_number' => $now->dayOfWeek,
            'month' => $now->month,
            'month_name' => $now->monthName,
            'year' => $now->year,
            'week_of_year' => $now->weekOfYear,
            'hour' => $now->hour,
            'minute' => $now->minute,
            'unix_timestamp' => $now->getTimestamp(),
        ];
    }
}
