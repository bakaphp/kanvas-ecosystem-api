<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesMessageForTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Read the FULL text of a message (an ingested meeting transcript, an email, a long note) by id.
 * The wake prompt only ever carries a short preview — a 50-minute transcript is far larger than any
 * prompt should hold — so agents that need the complete content pull it here, paging with `offset`
 * until `has_more` is false. Tenant-scoped: only messages in the agent's own app + company resolve.
 */
#[AgentTool(name: 'Read Message Content')]
class ReadMessageContentTool extends Tool
{
    use HasKanvasContext;
    use ResolvesMessageForTool;

    // Chars returned per read. NeuronAI caps a single tool at getMaxRuns() executions per turn, so the
    // chunk has to be large enough that even a long transcript pages out within that cap: at 40k chars ×
    // MAX_RUNS pages we cover ~1M chars, far beyond any real meeting. Too small a chunk (the old 12k)
    // needs 11+ pages on a 130k transcript and throws ToolRunsExceededException mid-read.
    private const int CHUNK = 40000;

    // Paging headroom above NeuronAI's default of 10 — CHUNK × MAX_RUNS is the largest message the agent
    // can fully read in one turn.
    private const int MAX_RUNS = 25;

    public function __construct()
    {
        parent::__construct(
            name: 'read_message_content',
            description: 'Read the FULL content of a message by its id (e.g. the transcript/email a trigger '
                . 'references). Long content is returned in chunks: start at offset 0, then pass the returned '
                . 'next_offset to keep reading until has_more is false. Always read the ENTIRE content before '
                . 'you plan — the trigger preview is only the opening.',
        );

        $this->setMaxRuns(self::MAX_RUNS);
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
                description: 'The message id to read.',
                required: true,
            ),
            new ToolProperty(
                name: 'offset',
                type: PropertyType::INTEGER,
                description: 'Character offset to start from (default 0). Use the previous call\'s next_offset '
                    . 'to page through long content.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $message_id, ?int $offset = null): array
    {
        $offset = max(0, (int) $offset);

        $message = $this->resolveMessageOrError(
            $message_id,
            "Message {$message_id} was not found in this project's tenant.",
        );

        if (is_array($message)) {
            return $message;
        }

        $content = $this->contentOf($message);
        $total = mb_strlen($content);
        $slice = $offset < $total ? mb_substr($content, $offset, self::CHUNK) : '';
        $next = $offset + mb_strlen($slice);

        return [
            'message_id' => $message_id,
            'total_length' => $total,
            'offset' => $offset,
            'content' => $slice,
            'has_more' => $next < $total,
            'next_offset' => $next < $total ? $next : null,
        ];
    }

    private function contentOf(Message $message): string
    {
        $payload = $message->message;

        if (is_array($payload)) {
            foreach (['content', 'text', 'body', 'message'] as $key) {
                if (isset($payload[$key]) && is_string($payload[$key])) {
                    return $payload[$key];
                }
            }

            return '';
        }

        return is_scalar($payload) ? (string) $payload : '';
    }
}
