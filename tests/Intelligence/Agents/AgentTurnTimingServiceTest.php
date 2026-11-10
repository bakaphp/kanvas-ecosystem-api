<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Neuron\Observability\TurnSetupStage;
use Kanvas\Intelligence\Agents\Services\AgentTurnTimingService;
use Mockery;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\RAG\Observability\Retrieving;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Observability\WorkflowStart;
use ReflectionClass;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\CapturingNeuronProvider;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

final class AgentTurnTimingServiceTest extends TestCase
{
    use MakesAgents;

    private float $now = 1000.0;

    public function testItSplitsATurnIntoItsStages(): void
    {
        $timing = new AgentTurnTimingService(fn (): float => $this->now);
        $question = new UserMessage('Where is the store?');
        $tool = new ToolCall('find_lead');

        $this->emit($timing, new TurnSetupStage('history-loaded'), after: 3.5);
        $this->emit($timing, new TurnSetupStage('lead-memory-ready'), after: 20.0);
        $this->emit($timing, new WorkflowStart([]), after: 0.5);
        $this->emit($timing, new Retrieving($question), after: 0.0);
        $this->emit($timing, new Retrieved($question, []), after: 1.0);
        $this->emit($timing, new InferenceStart($question), after: 0.0);
        $this->emit($timing, $this->inferenceStop(), after: 4.0);
        $this->emit($timing, new ToolCalling($tool), after: 0.0);
        $this->emit($timing, new ToolCalled($tool), after: 2.0);
        $this->emit($timing, new InferenceStart($question), after: 0.0);
        $this->emit($timing, $this->inferenceStop(), after: 3.0);
        $this->now += 1.0;

        $summary = $timing->summary();

        $this->assertSame(35_000, $summary['total_ms']);
        $this->assertSame(24_000, $summary['setup_ms']);
        $this->assertSame(7_000, $summary['model_ms']);
        $this->assertSame(2, $summary['model_calls']);
        $this->assertSame(1_000, $summary['retrieval_ms']);
        $this->assertSame(2_000, $summary['tools_ms']);
        $this->assertSame(1_000, $summary['other_ms']);
        $this->assertSame(['find_lead' => 2_000], $summary['slowest_tools']);
        $this->assertSame(
            ['step' => 'setup-history-loaded -> setup-lead-memory-ready', 'ms' => 20_000],
            $summary['slowest_steps'][0],
            'The slowest step names the stage that ran long'
        );
    }

    public function testParallelToolCallsAreEachTimedFromTheirOwnStart(): void
    {
        $timing = new AgentTurnTimingService(fn (): float => $this->now);
        $fast = new ToolCall('find_lead', 'call-1');
        $slow = new ToolCall('find_deals', 'call-2');

        $this->emit($timing, new ToolCalling($fast, true), after: 0.0);
        $this->emit($timing, new ToolCalling($slow, true), after: 0.0);
        $this->emit($timing, new ToolCalled($fast), after: 1.0);
        $this->emit($timing, new ToolCalled($slow), after: 4.0);

        $this->assertSame(
            ['find_deals' => 5_000, 'find_lead' => 1_000],
            $timing->summary()['slowest_tools']
        );
    }

    public function testThreadWaitIsKeptOutOfTheTurnTotal(): void
    {
        $timing = new AgentTurnTimingService(fn (): float => $this->now);
        $this->now += 40.0;

        $summary = $timing->summary(threadWaitMs: 30_000);

        $this->assertSame(10_000, $summary['total_ms']);
        $this->assertSame(30_000, $summary['thread_wait_ms']);
    }

    public function testASlowTurnIsLoggedWithItsBreakdown(): void
    {
        config(['kanvas.agents.slow_turn_log_ms' => 1]);
        Log::spy();

        $this->runTurn();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Slow agent turn'
                && $context['model_calls'] === 1
                && $context['runs'] === 1
                && array_key_exists('slowest_steps', $context))
            ->once();
    }

    public function testAFastTurnIsNotLogged(): void
    {
        config(['kanvas.agents.slow_turn_log_ms' => 600_000]);
        Log::spy();

        $this->runTurn();

        Log::shouldNotHaveReceived('warning', [
            'Slow agent turn',
            Mockery::any(),
        ]);
    }

    private function runTurn(): void
    {
        $user = auth()->user();
        $agent = $this->makeAgentFor($user);

        $handler = new CapturingNeuronAgentStub();
        $handler->setConfiguration(agent: $agent, user: $user);
        $handler->setThreadId('timing-' . Str::uuid());
        $handler->capturedProvider = new CapturingNeuronProvider();

        new RunNeuronChatAction(
            agent: $agent,
            session: null,
            message: 'Where is the store?',
            app: app(Apps::class),
            user: $user,
            handler: $handler,
        )->execute();
    }

    private function emit(AgentTurnTimingService $timing, object $event, float $after): void
    {
        $this->now += $after;
        $timing($event);
    }

    private function inferenceStop(): InferenceStop
    {
        return new ReflectionClass(InferenceStop::class)->newInstanceWithoutConstructor();
    }
}
