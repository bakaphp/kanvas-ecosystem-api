<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence\Concerns;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;

/**
 * What a remembering stub needs to run a real turn with no network, no Typesense and no conversation
 * rows: company memory on, in the shared in-memory store, with a provider that records everything each
 * model call reads. Neuron sends retrieved memory with the turn's user message as <EXTRA-CONTEXT>, not
 * in the system prompt, so the record is the system prompt plus the messages.
 */
trait RemembersInProcess
{
    use RunsOffline;

    /** @var list<string> */
    public array $modelInputs = [];

    private ?AIProviderInterface $stubProvider = null;

    protected function providerReply(): string
    {
        return 'Noted.';
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->stubProvider ??= new class ($this, $this->providerReply()) extends FakeNeuronProvider {
            private string $systemPrompt = '';

            public function __construct(private readonly object $agent, string $reply)
            {
                parent::__construct($reply);
            }

            #[Override]
            public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
            {
                $this->systemPrompt = $prompt instanceof SystemMessage
                    ? self::encode($prompt)
                    : (string) $prompt;

                return $this;
            }

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $this->agent->modelInputs[] = implode("\n", [$this->systemPrompt, ...array_map(self::encode(...), $messages)]);

                return parent::chat(...$messages);
            }

            private static function encode(Message $message): string
            {
                return (string) json_encode($message->jsonSerialize(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
