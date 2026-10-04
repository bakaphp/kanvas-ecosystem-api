<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use Override;

/**
 * A generic agent on the real conversation store whose provider answers every turn with a long reply,
 * so a few turns outgrow a tiny summarization budget, and answers the summary prompt with a marker.
 * Each inference's message list is recorded so a test can see what the model was handed.
 */
class SummarizingNeuronAgentStub extends KanvasGenericNeuronAgent
{
    public const string SUMMARY_TEXT = 'SUMMARY-TEXT';

    public const string LONG_REPLY = 'lorem ipsum dolor sit amet ';

    /** @var list<list<Message>> */
    public array $inferences = [];

    public int $summaryRequests = 0;

    private ?AIProviderInterface $stubProvider = null;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->stubProvider ??= new class ($this) extends FakeNeuronProvider {
            public function __construct(private readonly SummarizingNeuronAgentStub $agent)
            {
                parent::__construct();
            }

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $last = end($messages);

                if ($last !== false && str_contains((string) $last->getContent(), 'comprehensive summary')) {
                    $this->agent->summaryRequests++;

                    return $this->respond(new AssistantMessage(SummarizingNeuronAgentStub::SUMMARY_TEXT));
                }

                $this->agent->inferences[] = array_values($messages);

                return $this->respond(new AssistantMessage(str_repeat(SummarizingNeuronAgentStub::LONG_REPLY, 100)));
            }
        };
    }

    #[Override]
    protected function summarizationMaxTokens(): int
    {
        return 300;
    }

    #[Override]
    protected function summarizationMessagesToKeep(): int
    {
        return 2;
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return [];
    }

    #[Override]
    public function instructions(): string
    {
        return 'Summarizing test agent';
    }
}
