<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\SearchHarnessRepositoryCodeTool;
use Mockery;
use Tests\TestCase;

class ResolvesCodingRepositoryForToolTest extends TestCase
{
    /**
     * Regression for KANVAS-ECOSYSTEM-6H2: the refusal passed the exception message where an array
     * payload was expected, so a repository the token cannot open fataled the turn with a TypeError.
     */
    public function testARepositoryTheTokenCannotOpenIsANotFoundOutcome(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $result = new SearchHarnessRepositoryCodeTool($this->agentWithToken())(
            'bakaphp/kanvas-core-api',
            'get-deposit'
        );

        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
        $this->assertStringContainsString('bakaphp/kanvas-core-api', $result['error']);
    }

    private function agentWithToken(): Agent
    {
        $settings = [AgentCustomFieldEnum::GIT_TOKEN->value => 'ghp_test'];

        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('get')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default
        );

        return $agent;
    }
}
