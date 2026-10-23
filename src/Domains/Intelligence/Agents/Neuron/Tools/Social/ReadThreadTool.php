<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\PresentsChannelMessages;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesMessageForTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The window and the search flatten a channel into a list; the answer to "why did that happen" is
 * usually in the replies under one message, which that list interleaves with everything else.
 */
#[AgentTool(name: 'Read Thread', category: 'social')]
class ReadThreadTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use PresentsChannelMessages;
    use ResolvesMessageForTool;
    use TrackByInputs;

    protected string $name = 'read_thread';

    protected ?string $description = 'Read a whole thread: the root message and every reply under it, oldest first, '
        . 'with who wrote each one. Pass any message_id in the thread (from read_channel_window or '
        . 'search_channel_messages) — a reply resolves to its root. Use it to answer "why did that happen" '
        . 'or "what was decided": the reason is in the replies, not in the line that was flagged. Long '
        . 'texts are truncated; read_message_content gives one in full. Re-reading a thread in the same '
        . 'turn returns the same answer.';

    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'message_id',
                type: PropertyType::INTEGER,
                description: 'Any message in the thread: the root or one of its replies.',
                required: true,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many replies to return. Default ' . self::DEFAULT_LIMIT . ', maximum ' . self::MAX_LIMIT . '.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $message_id, ?int $limit = null): array
    {
        $limit = $this->clampLimit($limit, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        return $this->oncePerTurn(
            ['message_id' => $message_id, 'limit' => $limit],
            fn (): array => $this->read($message_id, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $messageId, int $limit): array
    {
        if (! $this->hasTenantContext()) {
            return $this->channelToolWithoutTenant('thread read');
        }

        $message = $this->resolveMessageOrError($messageId);

        if (is_array($message)) {
            return ['status' => 'error', 'message' => $message['error']];
        }

        $root = $this->root($message);
        $replies = $this->replies($root, $limit + 1);
        $hasMore = $replies->count() > $limit;

        $result = [
            'status' => 'success',
            'root' => $this->describeChannelMessage($root),
            'returned' => min($replies->count(), $limit),
            'has_more' => $hasMore,
            'replies' => array_map(
                fn (Message $reply): array => $this->describeChannelMessage($reply),
                $replies->take($limit)->all()
            ),
        ];

        if ($hasMore) {
            $result['note'] = 'The thread has more replies than returned; raise limit to read the rest.';
        } elseif ($replies->isEmpty()) {
            $result['note'] = 'This message has no replies.';
        }

        return $result;
    }

    private function root(Message $message): Message
    {
        if ((int) $message->parent_id <= 0) {
            return $message;
        }

        $root = $this->resolveMessageOrError((int) $message->parent_id);

        return $root instanceof Message ? $root : $message;
    }

    /**
     * @return Collection<int, Message>
     */
    private function replies(Message $root, int $limit): Collection
    {
        $replies = Message::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('parent_id', $root->getId())
            ->notDeleted()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($replies->isNotEmpty()) {
            return $replies;
        }

        // A Slack reply whose root predates the invite (the backfill never reached it) is stored
        // top-level with the thread timestamp on its payload, so its siblings are found by that.
        $threadTs = (string) ($root->message['slack_thread_ts'] ?? '');
        $slackChannel = (string) ($root->message['slack_channel'] ?? '');

        if ($threadTs === '' || $slackChannel === '') {
            return $replies;
        }

        return Message::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereKeyNot($root->getId())
            ->where('message->slack_channel', $slackChannel)
            ->where('message->slack_thread_ts', $threadTs)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
