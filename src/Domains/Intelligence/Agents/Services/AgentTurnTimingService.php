<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Closure;
use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\RAG\Observability\Retrieving;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Observability\MiddlewareStart;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;

/**
 * Where one agent turn spends its time. `duration_ms` is a single number, which cannot tell a slow
 * model from a slow query or a retry storm. Subscribed to the handler's events for one turn, then
 * logged when the turn runs long.
 */
final class AgentTurnTimingService
{
    private const int SLOWEST_GAPS = 6;

    private const int MIN_GAP_MS = 250;

    private readonly float $startedAt;

    private float $lastAt;

    private string $lastLabel = 'turn-start';

    /** @var array<string, float> */
    private array $open = [];

    /** @var array<string, int> */
    private array $stageMs = [
        'setup' => 0,
        'model' => 0,
        'retrieval' => 0,
        'tools' => 0,
    ];

    private int $modelCalls = 0;

    private int $runs = 0;

    /** @var array<string, int> */
    private array $toolMs = [];

    /** @var list<array{step: string, ms: int}> */
    private array $gaps = [];

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock Seconds as a float; tests pass a fake.
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->startedAt = ($this->clock)();
        $this->lastAt = $this->startedAt;
    }

    public function __invoke(ObservabilityEvent $event): void
    {
        $now = ($this->clock)();
        $label = $this->label($event);
        $gapMs = self::ms($now - $this->lastAt);

        if ($gapMs >= self::MIN_GAP_MS) {
            $this->gaps[] = ['step' => $this->lastLabel . ' -> ' . $label, 'ms' => $gapMs];
        }

        match (true) {
            $event instanceof WorkflowStart => $this->workflowStarted($now),
            $event instanceof InferenceStart => $this->open['model'] = $now,
            $event instanceof InferenceStop => $this->close('model', $now),
            $event instanceof Retrieving => $this->open['retrieval'] = $now,
            $event instanceof Retrieved => $this->close('retrieval', $now),
            $event instanceof ToolCalling => $this->open[self::toolKey($event->tool)] = $now,
            $event instanceof ToolCalled => $this->toolCalled($event, $now),
            default => null,
        };

        $this->lastAt = $now;
        $this->lastLabel = $label;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $threadWaitMs = 0): array
    {
        $totalMs = max(0, self::ms(($this->clock)() - $this->startedAt) - $threadWaitMs);
        $gaps = $this->gaps;
        usort($gaps, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $toolMs = $this->toolMs;
        arsort($toolMs);

        return [
            'total_ms' => $totalMs,
            'thread_wait_ms' => $threadWaitMs,
            'setup_ms' => $this->stageMs['setup'],
            'model_ms' => $this->stageMs['model'],
            'model_calls' => $this->modelCalls,
            'retrieval_ms' => $this->stageMs['retrieval'],
            'tools_ms' => $this->stageMs['tools'],
            'other_ms' => max(0, $totalMs - array_sum($this->stageMs)),
            'runs' => $this->runs,
            'slowest_tools' => array_slice($toolMs, 0, self::SLOWEST_GAPS, true),
            'slowest_steps' => array_slice($gaps, 0, self::SLOWEST_GAPS),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logIfSlow(Agent $agent, int $threadWaitMs, array $context = []): void
    {
        $threshold = (int) config('kanvas.agents.slow_turn_log_ms', 30_000);
        $summary = $this->summary($threadWaitMs);

        if ($threshold <= 0 || $summary['total_ms'] < $threshold) {
            return;
        }

        Log::warning('Slow agent turn', [
            'agent_id' => $agent->getId(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            ...$context,
            ...$summary,
        ]);
    }

    private function workflowStarted(float $now): void
    {
        // The first run's start closes the setup; a correction retry starts a second run on the handler.
        if ($this->runs === 0) {
            $this->stageMs['setup'] = self::ms($now - $this->startedAt);
        }

        $this->runs++;
    }

    private function toolCalled(ToolCalled $event, float $now): void
    {
        $spent = $this->close(self::toolKey($event->tool), $now, 'tools');
        $name = $event->tool->getName();
        $this->toolMs[$name] = ($this->toolMs[$name] ?? 0) + $spent;
    }

    private function close(string $span, float $now, ?string $stage = null): int
    {
        if (! isset($this->open[$span])) {
            return 0;
        }

        $stage ??= $span;
        $spent = self::ms($now - $this->open[$span]);
        unset($this->open[$span]);
        $this->stageMs[$stage] += $spent;

        if ($stage === 'model') {
            $this->modelCalls++;
        }

        return $spent;
    }

    /**
     * Parallel tool calls are all announced before any of them returns, so each needs its own span.
     * Their times add up, which can put `tools_ms` over the wall clock of the round.
     */
    private static function toolKey(ToolCall $tool): string
    {
        return 'tool:' . ($tool->getCallId() ?? $tool->getName());
    }

    private function label(ObservabilityEvent $event): string
    {
        return match (true) {
            $event instanceof WorkflowNodeStart => $event->name() . ':' . class_basename($event->node),
            $event instanceof MiddlewareStart => $event->name() . ':' . class_basename($event->middleware),
            $event instanceof ToolCalling, $event instanceof ToolCalled => $event->name() . ':' . $event->tool->getName(),
            default => $event->name(),
        };
    }

    private static function ms(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
