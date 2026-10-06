<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Laravel\Concerns\HasKanvasContext;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Override;

abstract class KanvasAgentAsTool implements Agent, CanActAsTool, HasTools
{
    use HasKanvasContext;
    use Promptable;

    /**
     * laravel-ai budgets 1.5 steps per tool, so a one-tool sub-agent gets two: one tool call and no
     * turn left to answer in. A sub-agent exists to run a few calls and report, so it gets room for that.
     */
    public const int DEFAULT_MAX_STEPS = 8;

    public function maxSteps(): int
    {
        return self::DEFAULT_MAX_STEPS;
    }

    #[Override]
    final public function tools(): iterable
    {
        $tools = [];

        foreach ($this->agentTools() as $tool) {
            if (($tool instanceof KanvasToolInterface || $tool instanceof self) && isset($this->app, $this->company)) {
                $tool->withContext($this->app, $this->company, $this->agent);
            }

            $tools[] = KanvasSubAgentTool::wrap($tool);
        }

        return $tools;
    }

    abstract public function agentTools(): iterable;
}
