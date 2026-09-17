<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\TestCase;

class BoundToolResultsMiddlewareTest extends TestCase
{
    public function testOversizedResultIsTruncatedWithAMarker(): void
    {
        Log::spy();

        $tool = $this->toolWithResult(str_repeat('a', BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT * 3));

        $this->runAfter(new AgentState(), $tool);

        $this->assertLessThan(BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT + 500, mb_strlen($tool->getResult()));
        $this->assertStringContainsString('[... truncated: showing', $tool->getResult());
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['tool'] === 'get_file'
                && $context['kept_chars'] === BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT);
    }

    public function testSmallResultIsLeftUntouched(): void
    {
        Log::spy();

        $tool = $this->toolWithResult('{"files":3}');

        $this->runAfter(new AgentState(), $tool);

        $this->assertSame('{"files":3}', $tool->getResult());
        Log::shouldNotHaveReceived('warning');
    }

    public function testResultsEarlierInTheTurnSpendTheBudget(): void
    {
        $state = $this->stateWithHistory([
            new UserMessage('Review pull request 233'),
            ...$this->toolRound('get_diff', str_repeat('d', BoundToolResultsMiddleware::MAX_CHARS_PER_TURN - 110_000)),
            ...$this->toolRound('get_file', str_repeat('f', 100_000)),
        ]);

        $almostFull = $this->toolWithResult(str_repeat('h', 50_000));
        $this->runAfter($state, $almostFull);

        $this->assertLessThan(10_500, mb_strlen($almostFull->getResult()));
        $this->assertStringContainsString('[... truncated: showing 10000 of 50000', $almostFull->getResult());

        $state->getChatHistory()->addMessage(new ToolCallMessage(null, [$almostFull]));
        $state->getChatHistory()->addMessage(new ToolResultMessage([$almostFull]));
        $spent = $this->toolWithResult(str_repeat('i', 50_000));
        $this->runAfter($state, $spent);

        $this->assertStringStartsWith('[Tool output omitted', $spent->getResult());
    }

    public function testPreviousTurnsDoNotCountAgainstTheCurrentTurn(): void
    {
        $state = $this->stateWithHistory([
            new UserMessage('Earlier question'),
            ...$this->toolRound('get_diff', str_repeat('d', BoundToolResultsMiddleware::MAX_CHARS_PER_TURN)),
            new AssistantMessage('Earlier answer'),
            new UserMessage('Review pull request 233'),
        ]);

        $tool = $this->toolWithResult(str_repeat('a', 20_000));
        $this->runAfter($state, $tool);

        $this->assertSame(20_000, mb_strlen($tool->getResult()));
    }

    public function testEveryKanvasNeuronAgentRegistersTheMiddlewareOnBothToolNodes(): void
    {
        $agent = new CapturingNeuronAgentStub();

        foreach ([new ToolNode(), new ParallelToolNode()] as $node) {
            $this->assertNotEmpty(array_filter(
                $agent->getMiddlewareForNode($node),
                fn (object $middleware): bool => $middleware instanceof BoundToolResultsMiddleware,
            ));
        }
    }

    public function testTheModelReceivesTheBoundedResultOnTheNextInference(): void
    {
        $provider = new ScriptedToolCallNeuronProvider(
            Tool::make('get_pull_request_diff', 'Returns the PR diff')
                ->setCallable(fn () => str_repeat('+ line', BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT))
        );

        $agent = new CapturingNeuronAgentStub();
        $agent->capturedProvider = $provider;
        $agent->chat(new UserMessage('Review pull request 233'))->run();

        $sent = array_values(array_filter(
            $provider->secondCallMessages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));

        $this->assertCount(1, $sent);
        $this->assertLessThan(
            BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT + 500,
            mb_strlen($sent[0]->getTools()[0]->getResult())
        );
    }

    private function runAfter(AgentState $state, Tool $tool): void
    {
        $event = new AIInferenceEvent('instructions', []);
        $event->setMessages(new ToolResultMessage([$tool]));

        new BoundToolResultsMiddleware()->after(new ToolNode(), $event, $state);
    }

    private function toolWithResult(string $result): Tool
    {
        return Tool::make('get_file', 'Returns a file')->setResult($result);
    }

    /**
     * @return array{ToolCallMessage, ToolResultMessage}
     */
    private function toolRound(string $name, string $result): array
    {
        $tool = Tool::make($name, 'A tool')->setResult($result);

        return [new ToolCallMessage(null, [$tool]), new ToolResultMessage([$tool])];
    }

    /**
     * @param list<Message> $messages
     */
    private function stateWithHistory(array $messages): AgentState
    {
        // Wide enough that Neuron's own trimmer never drops a message and muddies what the turn spent.
        $history = new InMemoryChatHistory(contextWindow: 10_000_000);

        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        return new AgentState()->setChatHistory($history);
    }
}
