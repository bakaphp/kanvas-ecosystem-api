<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Illuminate\Support\Carbon;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Override;

#[WorkflowAction(
    name: 'ElevenLabs Current Date And Time',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one answers with the current date and time in the COMPANY\'s '
        . 'timezone, so the voice agent can reason about \'tomorrow\' correctly.',
)]
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
