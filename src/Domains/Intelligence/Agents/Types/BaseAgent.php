<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\ChatHistory\RedisAgentChatHistory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\Tool;
use Override;

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
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        // return an AI provider (Anthropic, OpenAI, Gemini, Ollama, etc.)
        return new Gemini(
            key: $this->app->get(ConfigurationEnum::GEMINI_KEY->value),
            model: $this->app->get(ConfigurationEnum::GEMINI_MODEL->value) ?? 'gemini-2.0-flash-lite',
        );
    }

    #[Override]
    public function instructions(): string
    {
        $role = $this->agent->role;

        return new SystemPrompt(
            background: $role['background'],
            steps: $role['steps'],
            output: $role['output'],
        )->__toString();
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
        return new PineconeVectorStore(
            key: $this->app->get(ConfigurationEnum::PINECONE_API_KEY->value),
            indexUrl: $this->app->get(ConfigurationEnum::PINECONE_INDEX_URL->value),
            topK: 4
        );
    }

    public function getVectorStore(): VectorStoreInterface
    {
        return $this->vectorStore();
    }

    /*     #[Override]
        protected function searchDocuments(string $question): array
        {
            $embedding = $this->getEmbeddingsProvider()->embedText($question);

            // ✅ Custom filter logic here
            $docs = $this->getVectorStore()->similaritySearch(
                $embedding,
                ['user_id' => '2']
            );

            $retrievedDocs = [];
            foreach ($docs as $doc) {
                $retrievedDocs[\md5($doc->content)] = $doc;
            }

            return \array_values($retrievedDocs);
        } */

    /*     #[Override]
        protected function chatHistory(): AbstractChatHistory
        {
            return new FileChatHistory(
                directory: storage_path('chat'),
                key: '2',
                contextWindow: 50000
            );
        }
     */
    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        // Check if we have the required entity information
        if ($this->entity === null) {
            throw new \RuntimeException(
                'Entity information not set. Make sure to call setConfiguration() with a valid entity.'
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
