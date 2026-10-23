<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpFailureReasonEnum;
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
    private const int RECENT_FAILURES_LIMIT = 20;

    /** @var list<string> */
    private const TRACKED_EVENT_TYPES = [
        'lead.follow_up.queued',
        'lead.follow_up.sent',
        'lead.follow_up.skipped',
        'lead.follow_up.send_failed',
        'lead.follow_up.failed',
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
            ->orderByDesc('occurred_at')
            ->get(['event_type', 'payload', 'source_entity_id', 'occurred_at']);

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
        $failed = ['total' => 0, 'by_reason' => [], 'recent' => []];
        $exhausted = ['total' => 0, 'by_reason' => []];
        $queued = 0;
        $resumed = 0;
        $completed = 0;

        foreach ($events as $event) {
            $payload = (array) ($event->payload ?? []);

            if (FollowUpFailureReasonEnum::isFailure((string) ($payload['reason'] ?? ''))) {
                $this->countFailure($event, $payload, $failed);

                continue;
            }

            match ($event->event_type) {
                'lead.follow_up.queued' => $queued++,
                'lead.follow_up.sent' => $this->countSent($payload, $sent),
                'lead.follow_up.skipped' => $this->countWithReason($payload, $skipped),
                'lead.follow_up.exhausted' => $this->countWithReason($payload, $exhausted),
                'lead.follow_up.resumed' => $resumed++,
                'lead.pipeline.completed' => $completed++,
                default => null,
            };
        }

        return [
            'queued' => ['total' => $queued],
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
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

        $key = FollowUpFailureReasonEnum::reasonKey((string) ($payload['reason'] ?? 'unknown'));
        $bucket['by_reason'][$key] = ($bucket['by_reason'][$key] ?? 0) + 1;
    }

    /**
     * Events arrive newest first, so `recent` keeps the latest failures for the email.
     *
     * @param array<string, mixed> $payload
     * @param array{total: int, by_reason: array<string, int>, recent: list<array<string, mixed>>} $bucket
     */
    private function countFailure(Event $event, array $payload, array &$bucket): void
    {
        $this->countWithReason($payload, $bucket);

        if (count($bucket['recent']) >= self::RECENT_FAILURES_LIMIT) {
            return;
        }

        $reason = (string) $payload['reason'];
        $bucket['recent'][] = [
            'lead_id' => $event->source_entity_id,
            'reason' => FollowUpFailureReasonEnum::reasonKey($reason),
            'error' => $payload['error'] ?? FollowUpFailureReasonEnum::reasonDetail($reason),
            'channel' => $payload['channel'] ?? null,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ];
    }
}
