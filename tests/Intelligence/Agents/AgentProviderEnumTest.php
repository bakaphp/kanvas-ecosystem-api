<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Tests\TestCase;

class AgentProviderEnumTest extends TestCase
{
    private function deploymentWithProvider(?string $provider): AgentDeployment
    {
        $deployment = new AgentDeployment();
        $deployment->provider = $provider;

        return $deployment;
    }

    public function testForDeploymentResolvesValidProviderCaseInsensitively(): void
    {
        $this->assertSame(
            AgentProviderEnum::HERMES,
            AgentProviderEnum::forDeployment($this->deploymentWithProvider('Hermes')),
        );
        $this->assertSame(
            AgentProviderEnum::OPENCLAW,
            AgentProviderEnum::forDeployment($this->deploymentWithProvider('openclaw')),
        );
    }

    public function testForDeploymentDefaultsToOpenclawForEmpty(): void
    {
        $this->assertSame(
            AgentProviderEnum::OPENCLAW,
            AgentProviderEnum::forDeployment($this->deploymentWithProvider(null)),
        );
        $this->assertSame(
            AgentProviderEnum::OPENCLAW,
            AgentProviderEnum::forDeployment($this->deploymentWithProvider('')),
        );
    }

    public function testForDeploymentDoesNotThrowOnInvalidProvider(): void
    {
        // An LLM name like "anthropic" leaking into the runtime provider column must NOT fatal every
        // plan-change / kanban-sync path that reads it — it falls back to OPENCLAW.
        $this->assertSame(
            AgentProviderEnum::OPENCLAW,
            AgentProviderEnum::forDeployment($this->deploymentWithProvider('anthropic')),
        );
    }
}
