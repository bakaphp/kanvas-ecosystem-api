<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\HasEntityContext;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Read the recent traffic on a channel, so an agent woken on a single message can judge it against
 * the conversation around it — one line out of a group chat rarely means anything alone.
 *
 * Channel-agnostic on purpose: WhatsApp, Slack, email and internal channels all land in the same
 * `channel_messages` shape, so an agent granted this can watch any of them.
 */
#[AgentTool(name: 'Read Channel Window', category: 'social')]
class ReadChannelWindowTool extends Tool implements HasRunKey
{
    use GuardsRepeatCalls;
    use HasEntityContext;
    use HasKanvasContext;

    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;
    public const int PREVIEW_CHARS = 800;

    /**
     * Runs are keyed per channel (see getRunKey), so this bounds how many times the SAME channel may
     * be re-read — not how many channels an agent may look at. The turn ledger answers the second
     * identical read with a stop instruction, so reaching this cap means the model ignored it twice
     * (KANVAS-ECOSYSTEM-6A1: an agent with no tool for its task re-read one channel until the turn died).
     */
    private const int MAX_RUNS = 3;

    public function __construct()
    {
        parent::__construct(
            name: 'read_channel_window',
            description: 'Read the most recent messages on a channel, newest first, with who wrote each one. '
                . 'Call it before judging a single message — a line out of a group conversation usually only '
                . 'makes sense next to the ones around it. Defaults to the channel the record you were woken '
                . 'on belongs to, so you can normally call it with no arguments. Long messages come back '
                . 'truncated; use read_message_content for the full text of one. Re-reading a window you '
                . 'already read this turn returns nothing new — read it once, then act on it.',
        );

        $this->initRepeatGuard();
        $this->setMaxRuns(self::MAX_RUNS);
    }

    /**
     * Per-channel tracking so an agent comparing two channels doesn't spend one shared budget, while a
     * model stuck re-reading the same one still trips the cap.
     */
    #[Override]
    public function getRunKey(): string
    {
        $channelId = (int) $this->getInput('channel_id');

        return $this->getName() . ':' . ($channelId > 0 ? (string) $channelId : 'in-scope');
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'channel_id',
                type: PropertyType::INTEGER,
                description: 'Which channel to read. Omit to use the channel of the record you were woken on.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many recent messages to return. Default ' . self::DEFAULT_LIMIT
                    . ', maximum ' . self::MAX_LIMIT . '.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $channel_id = null, ?int $limit = null): array
    {
        // Keyed on the CLAMPED limit, not the raw one: omitted, 25 and 500 all read the same rows, so
        // keying on the argument would let a model vary a no-op parameter and re-run the same query.
        $limit = $this->resolveLimit($limit);

        return $this->oncePerTurn(
            [
                'channel_id' => $channel_id,
                'limit' => $limit,
            ],
            fn (): array => $this->read($channel_id, $limit),
        );
    }

    private function resolveLimit(?int $limit): int
    {
        return min(max((int) $limit ?: self::DEFAULT_LIMIT, 1), self::MAX_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(?int $channel_id, int $limit): array
    {
        if (! $this->hasTenantContext()) {
            return [
                'status' => 'error',
                'message' => 'This tool has no company context, so it cannot read a channel.',
            ];
        }

        $channel = $channel_id !== null
            ? $this->findChannel($channel_id)
            : $this->channelOfRecordInScope();

        if ($channel === null) {
            return [
                'status' => 'error',
                'message' => $channel_id !== null
                    ? 'Channel ' . $channel_id . ' does not exist in this company.'
                    : 'The record you were woken on is not on a channel, so pass channel_id explicitly.',
            ];
        }

        // Newest first: an agent woken on the latest message reads downward into history, so a window
        // cut short by the limit loses the oldest context rather than the message it was woken on.
        $messages = $channel->messages()
            ->where('messages.is_deleted', 0)
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->get();

        return [
            'status' => 'success',
            'channel_id' => $channel->getId(),
            'channel' => $channel->name,
            'returned' => $messages->count(),
            'messages' => $messages->map(fn (Message $message): array => $this->describe($message))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Message $message): array
    {
        $text = trim($message->contentText());

        return [
            'message_id' => $message->getId(),
            'author' => $message->user?->displayname ?? 'unknown',
            'author_user_id' => $message->users_id,
            'created_at' => (string) $message->created_at,
            'text' => Str::limit($text, self::PREVIEW_CHARS),
            'truncated' => mb_strlen($text) > self::PREVIEW_CHARS,
        ];
    }

    private function findChannel(int $channelId): ?Channel
    {
        return Channel::query()
            ->whereKey($channelId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * The record in scope is normally the message that triggered the wake, so its channel is the one
     * the agent means. A channel trigger can put the channel itself in scope instead, which is the
     * same answer arrived at directly. Anything else has no channel and the caller must name one.
     */
    private function channelOfRecordInScope(): ?Channel
    {
        if ($this->entity instanceof Channel) {
            return $this->findChannel($this->entity->getId());
        }

        if (! $this->entity instanceof Message) {
            return null;
        }

        $channel = $this->entity->channels()->first();

        return $channel instanceof Channel ? $this->findChannel($channel->getId()) : null;
    }
}
