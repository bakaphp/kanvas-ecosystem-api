<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Override;
use RuntimeException;
use Tests\Stubs\Intelligence\Tools\CallbackTool;

/**
 * An agent whose run and history live in process-wide statics, so a second instance on the same thread
 * stands in for the worker that picks a redelivered job up after the first one died. Its provider calls
 * `write_once` until it sees the result and, when told to, dies on the reply; `$writes` counts what the
 * tool actually did.
 */
class DurableNeuronAgentStub extends KanvasGenericNeuronAgent
{
    public static ?InMemoryPersistence $runs = null;

    public static ?InMemoryMessageStore $history = null;

    public static bool $dieOnNextReply = false;

    public static int $writes = 0;

    private ?AIProviderInterface $stubProvider = null;

    public static function reset(): void
    {
        self::$runs = new InMemoryPersistence();
        self::$history = new InMemoryMessageStore();
        self::$dieOnNextReply = false;
        self::$writes = 0;
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->stubProvider ??= new class () extends FakeNeuronProvider {
            /**
             * Decided from the conversation, not from a per-instance counter: the worker that resumes
             * a run sees the tool result as its first inference and must answer it, not call again.
             */
            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $last = end($messages);

                if (! $last instanceof ToolResultMessage) {
                    return $this->respond(new ToolCallMessage(null, [ToolCall::make('write_once', 'call-1', ['item' => 1])]));
                }

                if (DurableNeuronAgentStub::$dieOnNextReply) {
                    DurableNeuronAgentStub::$dieOnNextReply = false;

                    throw new RuntimeException('provider died mid-turn');
                }

                return $this->respond(new AssistantMessage('Done: the item was created once.'));
            }
        };
    }

    #[Override]
    protected function durableRunsActive(): bool
    {
        return true;
    }

    #[Override]
    protected function persistence(): PersistenceInterface
    {
        return self::$runs ??= new InMemoryPersistence();
    }

    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return self::$history ??= new InMemoryMessageStore();
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return [
            new CallbackTool('write_once', 'Creates the item', static function (): string {
                DurableNeuronAgentStub::$writes++;

                return 'created';
            }),
        ];
    }

    #[Override]
    public function instructions(): string
    {
        return 'Durable test agent';
    }
}
