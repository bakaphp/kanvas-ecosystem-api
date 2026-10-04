<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Memory;

use Illuminate\Support\Facades\Log;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use Throwable;

/**
 * Writes the turn that just ended into company memory: the last human message and the final reply as
 * one document, keyed by the reply's message id so a replayed segment upserts rather than duplicates.
 * Replaces AgentEndNode on agents that remember for the company; the tenant pair, agent and acting
 * human arrive as metadata, which is also what every later retrieval filters on.
 *
 * Runs inline so the next turn can already recall this one. A failed embedding never fails the turn:
 * the reply is already computed, the gap is logged for the nightly re-ingest sweep.
 */
final class ConversationMemoryNode extends Node implements AgentNodeInterface
{
    public const string SOURCE_TYPE = 'conversation';

    /**
     * @param array<string, int|string> $metadata
     */
    public function __construct(
        private readonly VectorStoreInterface $store,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly array $metadata,
        private readonly int $minChars,
    ) {
    }

    public function __invoke(AgentOutputEvent $event, AgentState $state, AgentResources $resources): StopEvent
    {
        $assistant = $state->getMessage();
        $user = self::lastUserMessage($state->request->messages)
            ?? self::lastUserMessage($resources->history->getMessages());

        if (! $assistant instanceof AssistantMessage || $assistant instanceof ToolCallMessage || $user === null) {
            return new StopEvent();
        }

        $content = self::transcript((string) $user->getContent(), (string) $assistant->getContent());

        if ($content === null || mb_strlen($content) < $this->minChars) {
            return new StopEvent();
        }

        $threadId = $resources->history->getThreadId();

        $this->memoize('conversation.ingest', function () use ($content, $threadId, $assistant): bool {
            $this->ingest($content, $threadId, (string) $assistant->getId());

            return true;
        });

        return new StopEvent();
    }

    /**
     * One turn as a memory document, the shape the nightly re-ingest sweep writes as well.
     *
     * @param array<string, int|string> $metadata
     */
    public static function document(
        string $content,
        string $threadId,
        string $messageId,
        array $metadata,
        ?int $createdAt = null
    ): Document {
        return new Document($content)
            ->setId(self::SOURCE_TYPE . ':' . $messageId)
            ->setSourceType(self::SOURCE_TYPE)
            ->setSourceName($threadId)
            ->setMetadata([
                ...$metadata,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $messageId,
                'created_at' => $createdAt ?? time(),
            ]);
    }

    /**
     * The one key set a memory document carries, for the live exit node and the reindex sweep alike.
     *
     * @return array<string, int|string>
     */
    public static function metadata(
        int $appId,
        int $companyId,
        int $agentId,
        int $usersId,
        ?string $entityType = null,
        ?int $entityId = null,
    ): array {
        $metadata = [
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'agent_id' => $agentId,
            'users_id' => $usersId,
        ];

        if ($entityType !== null && $entityId !== null) {
            $metadata['entity_type'] = $entityType;
            $metadata['entity_id'] = $entityId;
        }

        return $metadata;
    }

    public static function transcript(string $question, string $answer): ?string
    {
        $question = trim($question);
        $answer = trim($answer);

        if ($question === '' || $answer === '') {
            return null;
        }

        return "User: {$question}\nAssistant: {$answer}";
    }

    private function ingest(string $content, string $threadId, string $messageId): void
    {
        try {
            $document = self::document($content, $threadId, $messageId, $this->metadata);

            $this->store->getSchema()->validate($document);
            $this->store->addDocument($this->embeddings->embedDocument($document));
        } catch (Throwable $e) {
            report($e);
            Log::warning('Company memory ingest failed; the turn is answered, the memory is not written', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param Message[] $messages
     */
    private static function lastUserMessage(array $messages): ?UserMessage
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof UserMessage && ! $message instanceof ToolResultMessage) {
                return $message;
            }
        }

        return null;
    }
}
