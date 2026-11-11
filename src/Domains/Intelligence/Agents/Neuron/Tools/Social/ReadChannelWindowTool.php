<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\PresentsChannelMessages;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesChannelForTool;
use Kanvas\Intelligence\Agents\Traits\HasEntityContext;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
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
class ReadChannelWindowTool extends Tool
{
    use GuardsRepeatCalls;
    use HasEntityContext;
    use HasKanvasContext;
    use PresentsChannelMessages;
    use ResolvesChannelForTool;

    protected string $name = 'read_channel_window';

    protected ?string $description = 'Read the most recent messages on a channel, newest first, with who wrote each one. '
        . 'Call it before judging a single message — a line out of a group conversation usually only '
        . 'makes sense next to the ones around it. Defaults to the channel the record you were woken '
        . 'on belongs to, so you can normally call it with no arguments; name another one by channel_id, '
        . 'or by channel with its name (#ops) or Slack id (C0B4P1CH1ME). Long messages come back '
        . 'truncated; use read_message_content for the full text of one. Re-reading a window you '
        . 'already read this turn returns nothing new — read it once, then act on it.';

    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    /**
     * Runs are keyed per channel (see getRunKey), so this bounds how many times the SAME channel may
     * be re-read — not how many channels an agent may look at. The turn ledger answers the second
     * identical read with a stop instruction, so reaching this cap means the model ignored it twice
     * (KANVAS-ECOSYSTEM-6A1: an agent with no tool for its task re-read one channel until the turn died).
     */
    private const int MAX_RUNS = 3;

    public function __construct()
    {
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
        $reference = $this->channelReference($this->getInput('channel'));

        return $this->getName() . ':' . match (true) {
            $channelId > 0 => (string) $channelId,
            $reference !== '' => $reference,
            default => 'in-scope',
        };
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
                name: 'channel',
                type: PropertyType::STRING,
                description: 'The channel by name (#agents-logs), slug, or Slack channel id (C0B4P1CH1ME) '
                    . 'when you do not know its channel_id.',
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
    public function __invoke(?int $channel_id = null, ?string $channel = null, ?int $limit = null): array
    {
        $limit = $this->clampLimit($limit, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $reference = $this->channelReference($channel);

        return $this->oncePerTurn(
            [
                'channel_id' => $channel_id,
                'channel' => $reference,
                'limit' => $limit,
            ],
            fn (): array => $this->read($channel_id, $reference, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(?int $channelId, string $reference, int $limit): array
    {
        if (! $this->hasTenantContext()) {
            return $this->channelToolWithoutTenant('channel read');
        }

        $channel = $channelId === null && $reference === ''
            ? $this->channelOfRecordInScope()
            : $this->resolveChannelOrError($channelId, $reference);

        if ($channel === null) {
            return [
                'status' => 'error',
                'message' => 'The record you were woken on is not on a channel, so pass channel_id or channel explicitly.',
            ];
        }

        if (is_array($channel)) {
            return $channel;
        }

        // Newest first: an agent woken on the latest message reads downward into history, so a window
        // cut short by the limit loses the oldest context rather than the message it was woken on.
        $messages = $channel->messages()
            ->notDeleted()
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->get();

        return [
            'status' => 'success',
            'channel_id' => $channel->getId(),
            'channel' => $channel->name,
            'returned' => $messages->count(),
            'messages' => $messages->map(fn (Message $message): array => $this->describeChannelMessage($message))->all(),
        ];
    }

    /**
     * The record in scope is normally the message that triggered the wake, so its channel is the one
     * the agent means. A channel trigger can put the channel itself in scope instead, which is the
     * same answer arrived at directly. Anything else has no channel and the caller must name one.
     */
    private function channelOfRecordInScope(): ?Channel
    {
        if ($this->entity instanceof Channel) {
            return $this->findChannelById($this->entity->getId());
        }

        if (! $this->entity instanceof Message) {
            return null;
        }

        $channel = $this->entity->channels()->first();

        return $channel instanceof Channel ? $this->findChannelById($channel->getId()) : null;
    }
}
