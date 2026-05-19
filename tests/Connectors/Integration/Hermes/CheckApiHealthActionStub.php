<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Hermes;

use Kanvas\Connectors\Hermes\Actions\CheckApiHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Override;

/**
 * Test-only subclass that short-circuits the SSH+docker-exec probe with a canned status,
 * letting CheckApiHealthActionTest drive the state machine without real network I/O.
 */
class CheckApiHealthActionStub extends CheckApiHealthAction
{
    public function __construct(
        AgentDeployment $deployment,
        private readonly HealthCheckResultEnum $cannedStatus,
    ) {
        parent::__construct($deployment);
    }

    #[Override]
    protected function probe(): HealthCheckResultEnum
    {
        return $this->cannedStatus;
    }
}
