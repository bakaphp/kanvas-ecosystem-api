<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Kanvas\Connectors\OpenClaw\Actions\CheckCliHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Override;

/**
 * Short-circuits the `openclaw health` probe with a canned status so the test drives the
 * shared state machine without real SSH.
 */
class CheckCliHealthActionStub extends CheckCliHealthAction
{
    public function __construct(
        AgentDeployment $deployment,
        private readonly HealthCheckResultEnum $cannedStatus,
    ) {
        parent::__construct($deployment);
    }

    #[Override]
    protected function probe(Agent $agent): HealthCheckResultEnum
    {
        return $this->cannedStatus;
    }
}
