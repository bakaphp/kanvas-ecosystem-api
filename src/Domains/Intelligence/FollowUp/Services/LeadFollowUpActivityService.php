<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpFailureReasonEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Follow-up activity read straight from the ledger, for the daily summary email and
 * `lead:follow-up-status`. The all-companies counts are grouped in SQL: the dispatch writes one row per
 * candidate lead per hour, too many to load across an app. The per-company summary loads its rows.
 */
final class LeadFollowUpActivityService
{
    private const string REASON_KEY_SQL = "SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reason')), ':', 1)";

    /** @var list<string> */
    private const array EVENT_TYPES = [
        'lead.follow_up.queued',
        'lead.follow_up.sent',
        'lead.follow_up.skipped',
        'lead.follow_up.send_failed',
        'lead.follow_up.failed',
        'lead.follow_up.exhausted',
    ];

    /** @var list<string> */
    private const array SUMMARY_EVENT_TYPES = [
        ...self::EVENT_TYPES,
        'lead.follow_up.resumed',
        'lead.pipeline.completed',
    ];

    /**
     * @return array<int, array{queued: int, sent: int, skipped: int, failed: int, exhausted: int, last_sent_at: ?string, failed_by_reason: array<string, int>}>
     */
    public function countsByCompany(
        Apps $app,
        Carbon $from,
        Carbon $to,
        ?int $companyId = null
    ): array {
        $rows = $this->events($app, $from, $to)
            ->when($companyId !== null, fn (Builder $query) => $query->where('companies_id', $companyId))
            ->whereIn('event_type', self::EVENT_TYPES)
            ->selectRaw('companies_id, event_type, ' . self::REASON_KEY_SQL . ' AS reason_key, COUNT(*) AS total, MAX(occurred_at) AS last_at')
            ->groupBy('companies_id', 'event_type', 'reason_key')
            ->toBase()
            ->get();

        $companies = [];
        foreach ($rows as $row) {
            $id = (int) $row->companies_id;
            $companies[$id] ??= [
                'queued' => 0,
                'sent' => 0,
                'skipped' => 0,
                'failed' => 0,
                'exhausted' => 0,
                'last_sent_at' => null,
                'failed_by_reason' => [],
            ];
            $total = (int) $row->total;
            $reasonKey = (string) $row->reason_key;

            if (FollowUpFailureReasonEnum::isFailure($reasonKey)) {
                $companies[$id]['failed'] += $total;
                $companies[$id]['failed_by_reason'][$reasonKey] = ($companies[$id]['failed_by_reason'][$reasonKey] ?? 0) + $total;

                continue;
            }

            $bucket = match ($row->event_type) {
                'lead.follow_up.queued' => 'queued',
                'lead.follow_up.sent' => 'sent',
                'lead.follow_up.skipped' => 'skipped',
                'lead.follow_up.exhausted' => 'exhausted',
                default => null,
            };
            if ($bucket === null) {
                continue;
            }

            $companies[$id][$bucket] += $total;
            if ($bucket === 'sent') {
                $companies[$id]['last_sent_at'] = max($companies[$id]['last_sent_at'], (string) $row->last_at);
            }
        }

        ksort($companies);

        return $companies;
    }

    /**
     * One company's activity in the window, the shape the daily summary email renders: totals, sent by
     * channel and stage, skips, failures and exhaustions by reason, and the latest failures with the
     * lead's title.
     *
     * @return array<string, mixed>
     */
    public function summarize(
        Apps $app,
        Companies $company,
        Carbon $from,
        Carbon $to,
        int $recentFailuresLimit = 20
    ): array {
        $events = $this->events($app, $from, $to)
            ->where('companies_id', $company->getId())
            ->whereIn('event_type', self::SUMMARY_EVENT_TYPES)
            ->orderByDesc('occurred_at')
            ->get(['event_type', 'payload', 'source_entity_id', 'occurred_at']);

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
                $this->countFailure(
                    $event,
                    $payload,
                    $failed,
                    $recentFailuresLimit
                );

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

        $failed['recent'] = $this->withLeadTitles($app, $company, $failed['recent']);

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

    private function events(Apps $app, Carbon $from, Carbon $to): Builder
    {
        return Event::query()
            ->where('apps_id', $app->getId())
            ->whereBetween('occurred_at', [$from, $to]);
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
     * Events arrive newest first, so `recent` keeps the latest failures.
     *
     * @param array<string, mixed> $payload
     * @param array{total: int, by_reason: array<string, int>, recent: list<array<string, mixed>>} $bucket
     */
    private function countFailure(
        Event $event,
        array $payload,
        array &$bucket,
        int $limit
    ): void {
        $this->countWithReason($payload, $bucket);

        if (count($bucket['recent']) >= $limit) {
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

    /**
     * @param list<array<string, mixed>> $failures
     * @return list<array<string, mixed>>
     */
    private function withLeadTitles(Apps $app, Companies $company, array $failures): array
    {
        if ($failures === []) {
            return [];
        }

        $titles = Lead::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereIn('id', array_column($failures, 'lead_id'))
            ->pluck('title', 'id');

        return array_map(
            fn (array $failure): array => [...$failure, 'lead_title' => $titles[$failure['lead_id']] ?? null],
            $failures
        );
    }
}
