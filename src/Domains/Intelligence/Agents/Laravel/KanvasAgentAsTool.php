<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Override;

abstract class KanvasAgentAsTool implements Agent, CanActAsTool, HasTools
{
    use HasKanvasContext;
    use Promptable;

    #[Override]
    final public function tools(): iterable
    {
        $tools = [];

        foreach ($this->agentTools() as $tool) {
            if ($tool instanceof KanvasToolInterface && isset($this->app, $this->company)) {
                $tool->withContext($this->app, $this->company);
            }
            $tools[] = $tool;
        }

        return $tools;
    }

    abstract public function agentTools(): iterable;
}
