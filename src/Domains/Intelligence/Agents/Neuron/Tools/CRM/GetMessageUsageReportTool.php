<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesAnalyticsTimeframe;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The Engage usage report: how many SMS / emails the team sent and received over a timeframe,
 * split by who sent them — human team members vs the AI agent (Polly/Sally) — and by channel and
 * salesperson. Answers the manager questions "how many texts did we send last week?", "how many of
 * those were sent by the AI vs the reps?", "who sent the most messages?". Read-only, company-scoped
 * (internal-teammate capability). Restricted to real communication messages (SMS/email/WhatsApp),
 * so social posts and internal notes never inflate the numbers.
 */
#[AgentTool(name: 'Get Message Usage Report', category: 'crm')]
class GetMessageUsageReportTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    public function __construct()
    {
        parent::__construct(
            name: 'get_message_usage_report',
            description: 'Engage usage reporting for outbound/inbound customer messaging. Returns totals and '
                . 'breakdowns of SMS and email over a timeframe: total outbound, total inbound, messages sent by '
                . 'human team members vs by the AI agent, a per-channel split, a per-salesperson split, and a daily '
                . 'trend. Use for "how many texts did we send last week?", "how many messages did the AI send vs the '
                . 'reps?", "who sent the most emails this month?", "how many messages came in yesterday?". This is '
                . 'reporting only — it never sends anything.',
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
    ): array {
        $channel = strtolower(trim((string) $channel)) ?: 'all';
        if (! in_array($channel, ['sms', 'email', 'all'], true)) {
            return [
                'status' => 'error',
                'message' => 'Invalid channel. Use one of: sms, email, all.',
            ];
        }

        $messageTypeIds = $this->resolveMessageTypeIds($channel);
        if ($messageTypeIds === []) {
            return [
                'status' => 'success',
                'message' => 'No communication message types are configured for this channel, so there is nothing to report.',
                'totals' => ['messages' => 0, 'by_sender' => [], 'by_channel' => []],
                'by_salesperson' => [],
                'daily_trend' => [],
            ];
        }

        $args = $this->analyticsRangeArgs($timeframe, $from, $to);

        $result = new BuildAnalyticsAction(
            model: Message::class,
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
            groupBys: [
                'by_sender' => new AnalyticsGroupBy(
                    column: 'sender_type',
                    labelResolver: fn (mixed $key): string => $key === null
                        ? 'Other'
                        : (MessageSenderTypeEnum::tryFrom((string) $key)?->label() ?? (string) $key),
                ),
                'by_channel' => new AnalyticsGroupBy(
                    column: 'message_types_id',
                    relation: 'messageType',
                    labelColumn: 'name',
                ),
                'by_salesperson' => new AnalyticsGroupBy(
                    column: 'users_id',
                    relation: 'user',
                    labelColumn: 'displayname',
                ),
            ],
            extraScopes: fn (Builder $q) => $q->whereIn('message_types_id', $messageTypeIds),
        )->execute();

        return [
            'status' => 'success',
            'timeframe' => ['from' => $args['from'], 'to' => $args['to']],
            'channel' => $channel,
            'totals' => [
                'messages' => $result['total'],
                'by_sender' => $result['by_sender'],
                'by_channel' => $result['by_channel'],
            ],
            'by_salesperson' => $result['by_salesperson'],
            'daily_trend' => $result['periods'],
            'note' => 'by_sender splits the volume: "Team Member" = human-sent, "AI Agent" = sent by the AI, '
                . '"Customer" = inbound from customers. The AI agent also appears in by_salesperson (it has its own '
                . 'user), so use by_sender for the human-vs-AI totals.',
        ];
    }

    /**
     * Communication message-type ids for this app, narrowed to the requested channel. Restricting to
     * these ids is what keeps social posts / comments / internal notes out of the usage numbers.
     *
     * @return array<int, int>
     */
    private function resolveMessageTypeIds(string $channel): array
    {
        $keywords = match ($channel) {
            'sms' => ['sms', 'twilio'],
            'email' => ['email', 'mailgun'],
            default => [],
        };

        return MessageType::query()
            ->where('apps_id', $this->app->getId())
            ->get(['id', 'verb'])
            ->filter(function (MessageType $type) use ($keywords): bool {
                $verb = (string) $type->verb;
                if (! ChannelCategoryEnum::isCommunicationVerb($verb)) {
                    return false;
                }

                if ($keywords === []) {
                    return true;
                }

                foreach ($keywords as $keyword) {
                    if (str_contains($verb, $keyword)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (MessageType $type): int => (int) $type->id)
            ->values()
            ->all();
    }

}
