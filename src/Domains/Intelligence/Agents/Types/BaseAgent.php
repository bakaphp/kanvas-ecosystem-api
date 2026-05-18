<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\ChatHistory\RedisAgentChatHistory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\Tool;
use Override;
use RuntimeException;

class BaseAgent extends RAG
{
    protected ?Agent $agent = null;
    protected ?Apps $app = null;
    protected ?Companies $company = null;
    protected ?Model $entity = null;
    protected ?string $externalReferenceId = null;

    public function setConfiguration(
        Agent $agent,
        ?Model $entity = null,
        ?string $externalReferenceId = null,
    ): void {
        $this->agent = $agent;
        $this->entity = $entity;
        $this->app = $agent->app;
        $this->company = $agent->company;
        $this->externalReferenceId = $externalReferenceId;
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        // return an AI provider (Anthropic, OpenAI, Gemini, Ollama, etc.)
        return new Gemini(
            key: $this->app->get(ConfigurationEnum::GEMINI_KEY->value),
            model: $this->app->get(ConfigurationEnum::GEMINI_MODEL->value) ?? 'gemini-2.5-flash-lite',
            httpClient: new GuzzleHttpClient(timeout: 220, connectTimeout: 220),
        );
    }

    #[Override]
    public function instructions(): string
    {
        if ($this->agent === null) {
            throw new RuntimeException(
                'Agent not set. Make sure to call setConfiguration() before invoking the agent.',
            );
        }

        // Prefer the per-field shape (soul/instructions/output_format) on the
        // agent, falling back per-field to the AgentType so a type acts as the
        // base persona. This is the supported shape going forward — the legacy
        // structured `role` JSON path below stays for older agents only.
        $type = $this->agent->type;
        $coalesce = static fn (?string $a, ?string $b): ?string => ($a !== null && $a !== '') ? $a : $b;

        $parts = array_filter([
            $coalesce($this->agent->soul, $type?->soul),
            $coalesce($this->agent->instructions, $type?->instructions),
            $coalesce($this->agent->output_format, $type?->output_format),
        ]);

        if ($parts !== []) {
            return implode("\n\n", $parts);
        }

        // Legacy: role stored as a structured array { background, steps, output }
        // where each field MUST be an array — NeuronAI's SystemPrompt rejects
        // strings. Cast defensively so a string field doesn't TypeError here.
        $role = $this->agent->role;
        if (is_array($role) && isset($role['background'])) {
            return (string) new SystemPrompt(
                background: (array) $role['background'],
                steps: (array) ($role['steps'] ?? []),
                output: (array) ($role['output'] ?? []),
            );
        }

        return '';
    }

    #[Override]
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OpenAIEmbeddingsProvider(
            key: $this->app->get(ConfigurationEnum::OPEN_AI_EMBEDDINGS_KEY->value),
            model: $this->app->get(ConfigurationEnum::OPEN_AI_EMBEDDINGS_MODEL->value) ?? 'text-embedding-3-small',
        );
    }

    public function storeMemory(Document $document): void
    {
        $this->vectorStore()->addDocument($document);
    }

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        return $this->embeddings();
    }

    #[Override]
    protected function vectorStore(): VectorStoreInterface
    {
        /*  return new PineconeVectorStore(
             key: $this->app->get(ConfigurationEnum::PINECONE_API_KEY->value),
             indexUrl: $this->app->get(ConfigurationEnum::PINECONE_INDEX_URL->value),
             topK: 4
         ); */
        return new MemoryVectorStore();
    }

    public function getVectorStore(): VectorStoreInterface
    {
        return $this->vectorStore();
    }

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        // Check if we have the required entity information
        if ($this->entity === null) {
            throw new RuntimeException(
                'Entity information not set. Make sure to call setConfiguration() with a valid entity.',
            );
        }

        // Use our custom Redis-backed chat history that stores in agent_history
        return new RedisAgentChatHistory(
            agent: $this->agent,
            entity: $this->entity,
            externalReferenceId: $this->externalReferenceId,
            contextWindow: 50000
        );
    }

    /**
     * Chat with the agent using existing conversation context
     * This method automatically loads and maintains chat history
     */
    public function chatWithHistory(string|UserMessage $message): Message
    {
        // Get chat history instance
        $chatHistory = $this->chatHistory();

        // Get existing messages from chat history
        $existingMessages = $chatHistory->getAll();
        if (! empty($existingMessages)) {
            // Add the new message to existing conversation
            $existingMessages[] = $message instanceof UserMessage ? $message : new UserMessage($message);

            // Use the chat method with message history
            return $this->chat($existingMessages);
        } else {
            // No existing history, start new conversation
            return $this->chat($message);
        }
    }

    /**
     * Clear the chat history for this agent-entity combination
     */
    public function clearChatHistory(): void
    {
        $chatHistory = $this->chatHistory();
        $chatHistory->clear();
    }

    /**
     * Get the current chat history
     */
    #[Override]
    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory();
    }

    /**
     * Refresh chat history from database
     */
    public function refreshChatHistory(): void
    {
        $chatHistory = $this->chatHistory();
        if ($chatHistory instanceof RedisAgentChatHistory) {
            $chatHistory->refresh();
        }
    }

    #[Override]
    protected function tools(): array
    {
        /** @psalm-suppress MixedReturnTypeCoercion */
        return [
            Tool::make(
                'get_current_time',
                'Retrieve the current time from the system.',
            )->setCallable(fn () => [
                'time' => date('Y-m-d H:i:s'),
            ]),
        ];
    }
}
