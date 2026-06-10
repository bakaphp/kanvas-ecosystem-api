<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Daily rollup of `lead.follow_up.*` + `lead.pipeline.completed` ledger
 * events for one (app, company), emitting a single `follow_up.daily.summary`.
 *
 * Not idempotent: a re-run for the same date emits a second summary event.
 * Consumers pick latest by `occurred_at`.
 */
final class BuildLeadFollowUpDailySummaryAction
{
    /** @var list<string> */
    private const TRACKED_EVENT_TYPES = [
        'lead.follow_up.sent',
        'lead.follow_up.skipped',
        'lead.follow_up.exhausted',
        'lead.follow_up.resumed',
        'lead.pipeline.completed',
    ];

    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Carbon $forDate,
    ) {
    }

    public function execute(): Event
    {
        $tz = $this->company->timezone ?? 'UTC';
        $start = $this->forDate->copy()->setTimezone($tz)->startOfDay()->utc();
        $end = $this->forDate->copy()->setTimezone($tz)->endOfDay()->utc();

        $events = Event::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereIn('event_type', self::TRACKED_EVENT_TYPES)
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['event_type', 'payload']);

        $summary = $this->aggregate($events);
        $summary['for_date'] = $this->forDate->copy()->setTimezone($tz)->toDateString();
        $summary['app_id'] = $this->app->getId();
        $summary['company_id'] = $this->company->getId();

        return new AppendEventAction(
            new EventData(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'follow_up',
                eventType: 'follow_up.daily.summary',
                status: EventStatusEnum::INFO,
                sourceEntityType: null,
                sourceEntityId: null,
                payload: $summary,
            ),
        )->execute();
    }

    /**
     * @param iterable<Event> $events
     * @return array<string, mixed>
     */
    private function aggregate(iterable $events): array
    {
        $sent = ['total' => 0, 'by_channel' => [], 'by_stage' => []];
        $skipped = ['total' => 0, 'by_reason' => []];
        $exhausted = ['total' => 0, 'by_reason' => []];
        $resumed = 0;
        $completed = 0;

        foreach ($events as $event) {
            $payload = (array) ($event->payload ?? []);

            match ($event->event_type) {
                'lead.follow_up.sent' => $this->countSent($payload, $sent),
                'lead.follow_up.skipped' => $this->countWithReason($payload, $skipped),
                'lead.follow_up.exhausted' => $this->countWithReason($payload, $exhausted),
                'lead.follow_up.resumed' => $resumed++,
                'lead.pipeline.completed' => $completed++,
                default => null,
            };
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'exhausted' => $exhausted,
            'resumed' => ['total' => $resumed],
            'completed' => ['total' => $completed],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{total: int, by_channel: array<string, int>, by_stage: array<string, int>} $bucket
     */
    private function countSent(array $payload, array &$bucket): void
    {
        $bucket['total']++;

        foreach ((array) ($payload['channels'] ?? []) as $channel) {
            if (is_string($channel) && $channel !== '') {
                $bucket['by_channel'][$channel] = ($bucket['by_channel'][$channel] ?? 0) + 1;
            }
        }

        if (isset($payload['stage_id'])) {
            $key = (string) $payload['stage_id'];
            $bucket['by_stage'][$key] = ($bucket['by_stage'][$key] ?? 0) + 1;
        }
    }

    /**
     * Reason normalization — `agent: <text>` variants collapse into a single
     * `agent` bucket for dashboard readability. Full text still in raw events.
     *
     * @param array<string, mixed> $payload
     * @param array{total: int, by_reason: array<string, int>} $bucket
     */
    private function countWithReason(array $payload, array &$bucket): void
    {
        $bucket['total']++;

        $reason = (string) ($payload['reason'] ?? 'unknown');
        $colon = strpos($reason, ':');
        $key = $colon === false ? $reason : substr($reason, 0, $colon);
        $bucket['by_reason'][$key] = ($bucket['by_reason'][$key] ?? 0) + 1;
    }
}
