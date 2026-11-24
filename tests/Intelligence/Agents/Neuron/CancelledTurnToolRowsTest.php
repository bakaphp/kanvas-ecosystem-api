<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Enums\ConversationMessageKindEnum;
use Kanvas\Intelligence\Agents\Exceptions\AgentTurnCancelledException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Services\AgentTurnCancellationService;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\AIProviderInterface;
use Override;
use Tests\Stubs\Intelligence\CapturingNeuronProvider;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * Neuron writes a tool round to the history as soon as it settles, so a stop after a tool ran leaves
 * that round behind in the thread. The resend must still be answered alone.
 */
class CancelledTurnToolRowsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function testTheResendIsNotHandedTheStoppedTurn(): void
    {
        $user = auth()->user();
        $agentRecord = $this->makeAgentFor($user);
        $thread = 'cancel-' . Str::uuid();

        $tool = new CallbackTool('search_leads', 'Find leads.', function () use ($thread): string {
            AgentTurnCancellationService::request($thread);

            return 'found 3';
        });
        $stopped = $this->handler($agentRecord, $thread, new ScriptedToolCallNeuronProvider($tool));
        $stopped->addTool($tool);

        try {
            $this->runTurn($agentRecord, $stopped, 'Find my leads');
            $this->fail('The turn must stop after the tool ran');
        } catch (AgentTurnCancelledException) {
        }

        $this->assertGreaterThan(
            0,
            AgentConversationMessage::query()
                ->where('kind', ConversationMessageKindEnum::TOOL_CALL->value)
                ->whereHas('conversation', fn ($query) => $query->where('title', $thread))
                ->whereNull('archived_at')
                ->count(),
            'The round is on the thread, so what follows proves the resend is kept apart from it'
        );

        $provider = new CapturingNeuronProvider('Here you go.');
        $this->runTurn($agentRecord, $this->handler($agentRecord, $thread, $provider), 'Actually, find my deals');

        $sent = array_map(fn (Message $message): string => (string) json_encode($message), $provider->messages);

        $this->assertStringContainsString('find my deals', implode("\n", $sent));
        $this->assertStringNotContainsString('Find my leads', implode("\n", $sent));
        $this->assertSame(
            [],
            array_values(array_filter(
                $provider->messages,
                fn (Message $message): bool => $message instanceof ToolCallMessage || $message instanceof ToolResultMessage,
            )),
            'The stopped round must not reach the model'
        );
    }

    private function runTurn(Agent $agent, KanvasGenericNeuronAgent $handler, string $message): void
    {
        new RunNeuronChatAction(
            agent: $agent,
            session: null,
            message: $message,
            app: app(Apps::class),
            user: auth()->user(),
            handler: $handler,
        )->execute();
    }

    private function handler(Agent $agent, string $thread, FakeNeuronProvider $provider): KanvasGenericNeuronAgent
    {
        $handler = new class () extends KanvasGenericNeuronAgent {
            public FakeNeuronProvider $scripted;

            #[Override]
            protected function provider(): AIProviderInterface
            {
                return $this->scripted;
            }

            #[Override]
            public function instructions(): string
            {
                return 'Test agent';
            }

            #[Override]
            protected function tools(): array
            {
                return [];
            }
        };
        $handler->scripted = $provider;
        $handler->setConfiguration(agent: $agent, user: auth()->user());
        $handler->setThreadId($thread);

        return $handler;
    }
}
