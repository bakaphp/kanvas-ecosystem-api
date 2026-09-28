<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesMessageForTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use stdClass;

/**
 * Read the FULL text of a message (an ingested meeting transcript, an email, a long note) by id.
 * The wake prompt only ever carries a short preview — a 50-minute transcript is far larger than any
 * prompt should hold — so agents that need the complete content pull it here, paging with `offset`
 * until `has_more` is false. Tenant-scoped: only messages in the agent's own app + company resolve.
 */
#[AgentTool(name: 'Read Message Content', category: 'ecosystem')]
class ReadMessageContentTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesMessageForTool;

    private const int CHUNK = 40000;

    /**
     * Runs are keyed per page (see getRunKey), so this bounds how many times the SAME page may be
     * re-read — not how far the agent may page. The turn ledger below answers the second identical
     * read with a stop instruction, so reaching this cap means the model ignored it twice.
     */
    private const int MAX_RUNS = 3;

    /**
     * Every chunk we hand back lands in the agent's history, whose window is 50k tokens
     * (KanvasMessageHistory). Past roughly 30k tokens of a single message the trimmer starts
     * dropping the earlier pages — the model loses page 1, asks for offset 0 again, and the read
     * never converges (KANVAS-ECOSYSTEM-621). Cap what one turn can pull instead of pretending a
     * 1M-char message is readable in one pass.
     */
    private const int MAX_TURN_CHARS = 120000;

    /**
     * Distinct offsets each get their own run key, so NeuronAI's per-key cap can't bound a model
     * that keeps inventing new ones. This is the terminator for that case.
     */
    private const int MAX_TURN_CALLS = 12;

    /**
     * Ledger of what this turn already received. NeuronAI clones the registered tool for every call
     * (`clone $tool` in HandleWithTools::findTool) and the clone is shallow, so an object property
     * is shared by every call of the turn while staying scoped to this agent instance.
     */
    private stdClass $turn;

    public function __construct()
    {
        parent::__construct(
            name: 'read_message_content',
            description: 'Read the FULL content of a message by its id (e.g. the transcript/email a trigger '
                . 'references). Long content is returned in chunks: start at offset 0, then pass the returned '
                . 'next_offset to keep reading until has_more is false. Never repeat an offset you already '
                . 'received — re-reading a page returns nothing but a reminder to move on. Always read the '
                . 'ENTIRE content before you plan — the trigger preview is only the opening.',
        );

        $this->turn = new stdClass();
        $this->turn->calls = 0;
        $this->turn->chars = 0;
        $this->turn->pages = [];

        $this->setMaxRuns(self::MAX_RUNS);
    }

    /**
     * Per-page tracking so paging through a long transcript doesn't spend one shared budget, while a
     * model stuck re-requesting the same page still trips the cap.
     */
    #[Override]
    public function getRunKey(): string
    {
        return $this->getName() . ':' . (int) $this->getInput('message_id') . ':' . max(0, (int) $this->getInput('offset'));
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
     *
     * @throws ToolRunsExceededException
     */
    public function __invoke(int $message_id, ?int $offset = null): array
    {
        $offset = max(0, (int) $offset);
        $this->turn->calls++;

        if ($this->turn->calls > self::MAX_TURN_CALLS) {
            throw new ToolRunsExceededException(
                'read_message_content was called ' . $this->turn->calls . ' times in one turn ('
                . $this->turn->chars . ' chars delivered). The agent is not advancing through the content.'
            );
        }

        $message = $this->resolveMessageOrError(
            $message_id,
            "Message {$message_id} was not found in this project's tenant.",
        );

        if (is_array($message)) {
            return $message;
        }

        $content = $this->contentOf($message);
        $total = mb_strlen($content);

        if ($total === 0) {
            return $this->stop(
                $message_id,
                0,
                $offset,
                'This message carries no readable text. Do not read it again — work with the context you '
                    . 'already have.',
            );
        }

        if ($offset >= $total) {
            return $this->stop(
                $message_id,
                $total,
                $offset,
                "You have already reached the end of this message ({$total} chars). Stop reading and act on it.",
            );
        }

        $page = $message_id . ':' . $offset;

        if (isset($this->turn->pages[$page])) {
            return $this->stop(
                $message_id,
                $total,
                $offset,
                "You already received the chunk at offset {$offset} of message {$message_id} earlier in this "
                    . 'turn — it is above in the conversation. Continue from the last next_offset you were '
                    . 'given, or answer with what you have.',
            );
        }

        if ($this->turn->chars >= self::MAX_TURN_CHARS) {
            return $this->stop(
                $message_id,
                $total,
                $offset,
                'You have already read ' . $this->turn->chars . ' chars of message content this turn, which is '
                    . 'all one turn returns. Plan from what you have and ask for the rest in a follow-up if you '
                    . 'genuinely need it.',
            );
        }

        $slice = mb_substr($content, $offset, self::CHUNK);
        $length = mb_strlen($slice);
        $next = $offset + $length;

        $this->turn->pages[$page] = true;
        $this->turn->chars += $length;

        $hasMore = $next < $total && $this->turn->chars < self::MAX_TURN_CHARS;

        return [
            'message_id' => $message_id,
            'total_length' => $total,
            'offset' => $offset,
            'content' => $slice,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $next : null,
        ];
    }

    /**
     * A terminal answer: has_more false and no content, so the model has nothing to page toward.
     *
     * @return array<string, mixed>
     */
    private function stop(
        int $messageId,
        int $total,
        int $offset,
        string $reason
    ): array {
        return [
            'message_id' => $messageId,
            'total_length' => $total,
            'offset' => $offset,
            'content' => '',
            'has_more' => false,
            'next_offset' => null,
            'error' => $reason,
        ];
    }

    private function contentOf(Message $message): string
    {
        $payload = $message->message;

        if (is_string($payload)) {
            return $payload;
        }

        if (! is_array($payload)) {
            return is_scalar($payload) ? (string) $payload : '';
        }

        $hasKnownKey = false;

        foreach (['content', 'text', 'body', 'message'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $hasKnownKey = true;

            if (is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return $payload[$key];
            }
        }

        if ($hasKnownKey || $payload === []) {
            return '';
        }

        // An ingested payload can nest its text under a shape we don't know. Handing the agent the raw
        // JSON is worse prose but it is content — an empty string is what it keeps re-reading.
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
