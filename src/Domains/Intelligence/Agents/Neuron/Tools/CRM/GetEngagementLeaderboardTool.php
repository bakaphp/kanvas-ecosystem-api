<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Analytics\Actions\BuildEngagementLeaderboardAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesAnalyticsTimeframe;
use Kanvas\Social\Enums\MessageChannelEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The per-rep Engage usage leaderboard — the same numbers the weekly emailed report carries, on
 * demand and for any range. Sibling of get_message_usage_report: that one answers company-wide
 * totals, this one ranks individual reps.
 */
#[AgentTool(name: 'Get Engagement Leaderboard', category: 'crm')]
class GetEngagementLeaderboardTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    private const array SORTS = ['total', 'ai', 'rep', 'replies', 'resp', 'appts'];

    public function __construct()
    {
        parent::__construct(
            name: 'get_engagement_leaderboard',
            description: 'Per-salesperson Engage usage ranking over a timeframe: messages sent, how many the AI '
                . 'sent vs the rep, customer replies and reply rate, median rep response time, and appointments '
                . 'booked — plus a team total row. Use for "who sent the most last week?", "rank the reps by '
                . 'response time", "regenerate the weekly engage usage report", "which rep books the most '
                . 'appointments?". For company-wide totals with no per-rep breakdown use get_message_usage_report '
                . 'instead. This is reporting only — it never sends anything.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'timeframe',
                type: PropertyType::STRING,
                description: 'One of: today, yesterday, last_7_days, last_30_days. Defaults to last_7_days. '
                    . 'Ignored when explicit from/to dates are given.',
                required: false,
            ),
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'Filter by channel: sms, email, or all. Defaults to all.',
                required: false,
            ),
            new ToolProperty(
                name: 'from',
                type: PropertyType::STRING,
                description: 'Custom range start date (YYYY-MM-DD). Overrides timeframe. Requires "to".',
                required: false,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Custom range end date (YYYY-MM-DD). Overrides timeframe. Requires "from".',
                required: false,
            ),
            new ToolProperty(
                name: 'sort_by',
                type: PropertyType::STRING,
                description: 'Rank the rows by: total, ai, rep, replies, resp (fastest first), or appts. '
                    . 'Defaults to total.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $timeframe = null,
        ?string $channel = null,
        ?string $from = null,
        ?string $to = null,
        ?string $sort_by = null,
    ): array {
        $channelEnum = MessageChannelEnum::tryFrom(strtolower(trim((string) $channel)) ?: 'all');
        if ($channelEnum === null) {
            return [
                'status' => 'error',
                'message' => 'Invalid channel. Use one of: ' . implode(', ', MessageChannelEnum::values()) . '.',
            ];
        }

        $sortBy = strtolower(trim((string) $sort_by)) ?: 'total';
        if (! in_array($sortBy, self::SORTS, true)) {
            return [
                'status' => 'error',
                'message' => 'Invalid sort_by. Use one of: ' . implode(', ', self::SORTS) . '.',
            ];
        }

        $args = $this->analyticsRangeArgs($timeframe, $from, $to);

        $result = new BuildEngagementLeaderboardAction(
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
            channel: $channelEnum,
        )->execute();

        return [
            'status' => 'success',
            'timeframe' => ['from' => $args['from'], 'to' => $args['to']],
            'channel' => $channelEnum->value,
            'sort_by' => $sortBy,
            'rows' => $this->sortRows($result['rows'], $sortBy),
            'team' => $result['team'],
            'note' => 'Rows are keyed by the lead owner, so a rep\'s replies are the ones their own leads sent '
                . 'back — not messages they personally received. The AI agent is excluded as a row; its volume '
                . 'appears per rep as ai_sent. median_response_seconds counts human replies only and is null when '
                . 'a rep had no inbound to answer. Only messaging that flows through Kanvas is counted — a rep '
                . 'texting from a personal phone or emailing from Outlook is invisible here, so a low row is not '
                . 'by itself evidence of a quiet rep.',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows, string $sortBy): array
    {
        // Fastest-first for response time, and reps with no pairs sink rather than win on null.
        if ($sortBy === 'resp') {
            usort($rows, static fn (array $a, array $b): int =>
                ($a['median_response_seconds'] ?? PHP_INT_MAX) <=> ($b['median_response_seconds'] ?? PHP_INT_MAX));

            return $rows;
        }

        $column = match ($sortBy) {
            'ai' => 'ai_sent',
            'rep' => 'rep_sent',
            'replies' => 'replies',
            'appts' => 'appointments',
            default => 'total_sent',
        };

        usort($rows, static fn (array $a, array $b): int => $b[$column] <=> $a[$column]);

        return $rows;
    }
}
