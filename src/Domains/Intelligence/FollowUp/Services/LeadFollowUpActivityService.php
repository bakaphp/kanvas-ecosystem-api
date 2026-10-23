<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpFailureReasonEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Per-company follow-up activity read straight from the ledger for `lead:follow-up-status`. Counts are
 * grouped in SQL: the dispatch writes one row per candidate lead per hour, too many to load.
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

    /**
     * @return array<int, array{queued: int, sent: int, skipped: int, failed: int, exhausted: int, last_sent_at: ?string, failed_by_reason: array<string, int>}>
     */
    public function countsByCompany(Apps $app, Carbon $since, ?int $companyId = null): array
    {
        $rows = $this->events($app, $since, $companyId)
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
     * @return list<array{company_id: int, lead_id: int, reason: string, channel: ?string, error: ?string, occurred_at: string}>
     */
    public function recentFailures(
        Apps $app,
        Carbon $since,
        ?int $companyId,
        int $limit
    ): array {
        $reasons = array_column(FollowUpFailureReasonEnum::cases(), 'value');

        return $this->events($app, $since, $companyId)
            ->whereRaw(self::REASON_KEY_SQL . ' IN (' . implode(', ', array_fill(0, count($reasons), '?')) . ')', $reasons)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['companies_id', 'source_entity_id', 'payload', 'occurred_at'])
            ->map(function (Event $event): array {
                $payload = (array) $event->payload;
                $reason = (string) ($payload['reason'] ?? '');

                return [
                    'company_id' => (int) $event->companies_id,
                    'lead_id' => (int) $event->source_entity_id,
                    'reason' => FollowUpFailureReasonEnum::reasonKey($reason),
                    'channel' => $payload['channel'] ?? null,
                    'error' => $payload['error'] ?? FollowUpFailureReasonEnum::reasonDetail($reason),
                    'occurred_at' => (string) $event->occurred_at,
                ];
            })
            ->all();
    }

    private function events(Apps $app, Carbon $since, ?int $companyId): Builder
    {
        return Event::query()
            ->where('apps_id', $app->getId())
            ->when($companyId !== null, fn (Builder $query) => $query->where('companies_id', $companyId))
            ->whereIn('event_type', self::EVENT_TYPES)
            ->where('occurred_at', '>=', $since);
    }
}
