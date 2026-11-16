<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Neuron\Tools\Concerns;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;

/**
 * A supervisor tool is built for the dealer its agent supervises. The required constructor is also what
 * keeps it out of the tool catalog (AgentToolDiscoveryService skips tools that need runtime state), so
 * no other agent can be granted it.
 */
trait ScopedToSupervisedDealer
{
    use HasKanvasContext;

    public function __construct(Agent $supervisor)
    {
        $this->app = $supervisor->app;
        $this->company = $supervisor->company;
    }
}
