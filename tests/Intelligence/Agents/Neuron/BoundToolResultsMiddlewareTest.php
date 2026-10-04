<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use Kanvas\Intelligence\Agents\Neuron\Tools\Fallback\RefusedToolStub;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;
use ReflectionMethod;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\RepeatingToolCallNeuronProvider;
use Tests\Stubs\Intelligence\ScriptedToolCallNeuronProvider;
use Tests\Stubs\Intelligence\Tools\CallbackTool;
use Tests\TestCase;

class BoundToolResultsMiddlewareTest extends TestCase
{
    public function testOversizedResultIsTruncatedWithAMarker(): void
    {
        Log::spy();

        $call = $this->callWithResult('get_file', str_repeat('a', BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT * 3));

        $this->runAfter(new AgentState(), $this->resources([new UserMessage('Open the file')]), $call);

        $this->assertLessThan(BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT + 500, mb_strlen((string) $call->getResult()));
        $this->assertStringContainsString('[... truncated: showing', (string) $call->getResult());
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['tool'] === 'get_file'
                && $context['kept_chars'] === BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT);
    }

    public function testSmallResultIsLeftUntouched(): void
    {
        Log::spy();

        $call = $this->callWithResult('get_file', '{"files":3}');

        $this->runAfter(new AgentState(), $this->resources([new UserMessage('Open the file')]), $call);

        $this->assertSame('{"files":3}', (string) $call->getResult());
        Log::shouldNotHaveReceived('warning');
    }

    public function testResultsEarlierInTheTurnSpendTheBudget(): void
    {
        $state = new AgentState();
        $resources = $this->resources([
            new UserMessage('Review pull request 233'),
            ...$this->toolRound('get_diff', str_repeat('d', BoundToolResultsMiddleware::MAX_CHARS_PER_TURN - 110_000)),
            ...$this->toolRound('get_file', str_repeat('f', 100_000)),
        ]);

        $almostFull = $this->callWithResult('get_file', str_repeat('h', 50_000));
        $this->runAfter($state, $resources, $almostFull);

        $this->assertLessThan(10_500, mb_strlen((string) $almostFull->getResult()));
        $this->assertStringContainsString('[... truncated: showing 10000 of 50000', (string) $almostFull->getResult());

        // Once that round is in the history the turn is spent, so the next round ran but is withheld.

        $this->commit($resources, $almostFull);
        $spent = $this->callWithResult('get_file', str_repeat('i', 50_000));
        $this->runAfter($state, $resources, $spent);

        $this->assertStringStartsWith('[This call ran, but its output was withheld', (string) $spent->getResult());
        $this->assertTrue(BoundToolResultsMiddleware::exhausted($state));
    }

    /**
     * A hidden result leaves the model unable to tell whether a write happened, so it reports the item
     * as pending and the next turn creates it twice. Refusing the call keeps the report true: the live
     * tool is swapped out of the segment's registry for a stub that answers with the refusal.
     */
    public function testOnceTheBudgetIsSpentACallIsRefusedAndNeverRuns(): void
    {
        $runs = 0;
        $tool = new CallbackTool('create_workflow', 'Creates a workflow', function () use (&$runs): string {
            $runs++;

            return 'created';
        });
        $state = new AgentState();
        $resources = $this->spentResources([$tool]);

        $this->runBefore($state, $resources, 'create_workflow');

        $live = $resources->tools->find('create_workflow');
        $live->setInputs([]);
        $live->execute();

        $this->assertInstanceOf(RefusedToolStub::class, $live);
        $this->assertSame(0, $runs, 'a refused call must not reach the real tool');
        $this->assertSame(BoundToolResultsMiddleware::NOT_EXECUTED, (string) $live->getResult());
        $this->assertTrue(BoundToolResultsMiddleware::exhausted($state));
    }

    public function testUnderBudgetACallRunsNormally(): void
    {
        $runs = 0;
        $tool = new CallbackTool('create_workflow', 'Creates a workflow', function () use (&$runs): string {
            $runs++;

            return 'created';
        });
        $state = new AgentState();
        $resources = $this->resources([new UserMessage('Create the workflows')], [$tool]);

        $this->runBefore($state, $resources, 'create_workflow');

        $live = $resources->tools->find('create_workflow');
        $live->setInputs([]);
        $live->execute();

        $this->assertSame($tool, $live);
        $this->assertSame(1, $runs);
        $this->assertSame('created', (string) $live->getResult());
        $this->assertFalse(BoundToolResultsMiddleware::exhausted($state));
    }

    /** The refusal note is the truth about that call; overwriting it with "ran" would undo the point. */
    public function testARefusedCallKeepsItsNoteThroughTheOutputBound(): void
    {
        $call = $this->callWithResult('create_workflow', BoundToolResultsMiddleware::NOT_EXECUTED);

        $this->runAfter(new AgentState(), $this->spentResources(), $call);

        $this->assertSame(BoundToolResultsMiddleware::NOT_EXECUTED, (string) $call->getResult());
    }

    public function testExecutedCallsLeaveOutRefusedOnes(): void
    {
        $ran = ToolCall::make('create_workflow', 'c-1', ['item' => 1])->setResult('created');
        $refused = ToolCall::make('create_workflow', 'c-2', ['item' => 2])->setResult(BoundToolResultsMiddleware::NOT_EXECUTED);

        $state = new AgentState();
        foreach ([
            new UserMessage('Create the workflows'),
            new ToolCallMessage(null, [$ran]),
            new ToolResultMessage([$ran]),
            new ToolCallMessage(null, [$refused]),
            new ToolResultMessage([$refused]),
        ] as $message) {
            $state->addStep($message);
        }

        $this->assertSame(
            ['create_workflow:' . sha1((string) json_encode(['item' => 1]))],
            BoundToolResultsMiddleware::executedCalls($state),
        );
    }

    /**
     * Through the real agent loop, not the middleware alone: this is what proves the refusal lands before
     * the tool node executes, rather than after it as the output bound does.
     */
    public function testARealTurnStopsRunningToolsOnceItsBudgetIsSpent(): void
    {
        $runs = 0;
        $tool = new CallbackTool('export_workflow', 'Exports a workflow', function () use (&$runs): string {
            $runs++;

            return str_repeat('w', BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT);
        });

        $agent = new CapturingNeuronAgentStub()->setThreadId('budget-turn');
        $agent->capturedProvider = new RepeatingToolCallNeuronProvider($tool, rounds: 5);
        $agent->addTool($tool);
        $state = $agent->chat(new UserMessage('Export all five workflows'));

        // 150K + 150K + 100K spends the 400K budget, so rounds four and five are refused.
        $this->assertSame(3, $runs);
        $this->assertTrue(BoundToolResultsMiddleware::exhausted($state));
        $this->assertCount(3, BoundToolResultsMiddleware::executedCalls($state));
    }

    public function testPreviousTurnsDoNotCountAgainstTheCurrentTurn(): void
    {
        $resources = $this->resources([
            new UserMessage('Earlier question'),
            ...$this->toolRound('get_diff', str_repeat('d', BoundToolResultsMiddleware::MAX_CHARS_PER_TURN)),
            new AssistantMessage('Earlier answer'),
            new UserMessage('Review pull request 233'),
        ]);

        $call = $this->callWithResult('get_file', str_repeat('a', 20_000));
        $this->runAfter(new AgentState(), $resources, $call);

        $this->assertSame(20_000, mb_strlen((string) $call->getResult()));
    }

    /**
     * The workflow matches middleware to a node by instanceof, so one ToolNode registration also wraps
     * ParallelToolNode, which extends it.
     */
    public function testEveryKanvasNeuronAgentRegistersTheMiddlewareOnTheToolNode(): void
    {
        $agent = new CapturingNeuronAgentStub();

        $middleware = new ReflectionMethod($agent, 'getMiddleware')->invoke($agent);

        $this->assertNotEmpty(array_filter(
            $middleware[ToolNode::class] ?? [],
            fn (object $item): bool => $item instanceof BoundToolResultsMiddleware,
        ));
        $this->assertTrue(is_subclass_of(ParallelToolNode::class, ToolNode::class));
    }

    public function testTheModelReceivesTheBoundedResultOnTheNextInference(): void
    {
        $tool = new CallbackTool(
            'get_pull_request_diff',
            'Returns the PR diff',
            fn () => str_repeat('+ line', BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT),
        );
        $provider = new ScriptedToolCallNeuronProvider($tool);

        $agent = new CapturingNeuronAgentStub()->setThreadId('bounded-result');
        $agent->capturedProvider = $provider;
        $agent->addTool($tool);
        $agent->chat(new UserMessage('Review pull request 233'));

        $sent = array_values(array_filter(
            $provider->secondCallMessages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));

        $this->assertCount(1, $sent);
        $this->assertLessThan(
            BoundToolResultsMiddleware::MAX_CHARS_PER_RESULT + 500,
            mb_strlen((string) $sent[0]->getToolCalls()[0]->getResult())
        );
    }

    private function runBefore(AgentState $state, AgentResources $resources, string $name): void
    {
        $event = new ToolCallEvent(new ToolCallMessage(null, [ToolCall::make($name, 'c-before', [])]));

        new BoundToolResultsMiddleware()->before(
            new ToolNode(),
            $event,
            $state,
            $resources,
        );
    }

    /**
     * ToolNode hands the round to the next inference through the request; the round reaches the history
     * only after that inference, which commit() stands in for.
     */
    private function runAfter(AgentState $state, AgentResources $resources, ToolCall $call): void
    {
        $state->request = new InferenceRequest(
            new SystemMessage('test'),
            [new ToolCallMessage(null, [$call]), new ToolResultMessage([$call])],
        );

        new BoundToolResultsMiddleware()->after(
            new ToolNode(),
            AIInferenceEvent::fromRequest($state->request),
            $state,
            $resources,
        );
    }

    private function commit(AgentResources $resources, ToolCall $call): void
    {
        $resources->history->addMessage(new ToolCallMessage(null, [$call]));
        $resources->history->addMessage(new ToolResultMessage([$call]));
    }

    private function callWithResult(string $name, string $result): ToolCall
    {
        return ToolCall::make($name, uniqid('c-', true), [])->setResult($result);
    }

    /**
     * @return array{ToolCallMessage, ToolResultMessage}
     */
    private function toolRound(string $name, string $result): array
    {
        $call = $this->callWithResult($name, $result);

        return [new ToolCallMessage(null, [$call]), new ToolResultMessage([$call])];
    }

    /**
     * @param list<object> $tools
     */
    private function spentResources(array $tools = []): AgentResources
    {
        return $this->resources([
            new UserMessage('Create the workflows'),
            ...$this->toolRound('get_diff', str_repeat('d', BoundToolResultsMiddleware::MAX_CHARS_PER_TURN)),
        ], $tools);
    }

    /**
     * @param list<Message> $messages
     * @param list<object> $tools
     */
    private function resources(array $messages, array $tools = []): AgentResources
    {
        // Wide enough that Neuron's own trimmer never drops a message and muddies what the turn spent.
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread', 10_000_000);

        foreach ($messages as $message) {
            $history->addMessage($message);
        }

        return new AgentResources(
            new FakeNeuronProvider(),
            $history,
            new SystemMessage('test'),
            new ToolRegistry($tools),
        );
    }
}
