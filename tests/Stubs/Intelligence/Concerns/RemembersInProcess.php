<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence\Concerns;

use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;

/**
 * What a remembering stub needs to run a real turn with no network, no Typesense and no conversation
 * rows: company memory on, in the shared in-memory store, with a provider that records every system
 * prompt it is handed, which is where retrieved memory lands.
 */
trait RemembersInProcess
{
    use RunsOffline;

    /** @var list<string> */
    public array $systemPrompts = [];

    private ?AIProviderInterface $stubProvider = null;

    protected function providerReply(): string
    {
        return 'Noted.';
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->stubProvider ??= new class ($this, $this->providerReply()) extends FakeNeuronProvider {
            public function __construct(private readonly object $agent, string $reply)
            {
                parent::__construct($reply);
            }

            #[Override]
            public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
            {
                $this->agent->systemPrompts[] = $prompt instanceof SystemMessage
                    ? (string) json_encode($prompt->jsonSerialize())
                    : (string) $prompt;

                return $this;
            }
        };
    }

    #[Override]
    protected function companyMemoryEnabled(): bool
    {
        return true;
    }

    #[Override]
    protected function companyMemoryStore(): VectorStoreInterface
    {
        return SharedCompanyMemory::store();
    }

    #[Override]
    protected function companyMemoryEmbeddings(): EmbeddingsProviderInterface
    {
        return new ConstantEmbeddingsProvider();
    }
}
