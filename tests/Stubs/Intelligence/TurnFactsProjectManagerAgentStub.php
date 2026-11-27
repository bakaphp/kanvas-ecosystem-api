<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * The real ProjectManagerAgent on a fake provider. Its real toolset is built and kept in a static sink
 * with who the turn was for, then withheld from the model so the turn makes no tool call.
 */
class TurnFactsProjectManagerAgentStub extends ProjectManagerAgent
{
    public static ?bool $lastUnattended = null;
    public static ?Users $lastRequestingHuman = null;

    /** @var list<object> */
    public static array $lastTools = [];

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola PM');
    }

    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return new InMemoryMessageStore();
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        self::$lastUnattended = $this->isUnattendedTurn();
        self::$lastRequestingHuman = $this->requestingHuman();
        self::$lastTools = parent::tools();

        return [];
    }

    #[Override]
    public function instructions(): string
    {
        return 'Turn facts PM test agent';
    }
}
