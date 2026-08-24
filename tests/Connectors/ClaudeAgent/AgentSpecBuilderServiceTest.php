<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

final class AgentSpecBuilderServiceTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeAgent(array $attributes = []): Agent
    {
        return $this->makeClaudeAgent($this->currentApp, $this->currentCompany, ['config' => [], ...$attributes]);
    }

    public function testTheSandboxToolsetIsAlwaysDeclared(): void
    {
        $spec = new AgentSpecBuilderService($this->makeAgent())->build();

        $this->assertSame([['type' => AgentSpecBuilderService::AGENT_TOOLSET]], $spec->tools);
    }

    public function testAnExplicitClaudeModelWins(): void
    {
        $agent = $this->makeAgent(['config' => ['claude_model' => 'claude-sonnet-5']]);

        $this->assertSame('claude-sonnet-5', new AgentSpecBuilderService($agent)->build()->model);
    }

    /**
     * An agent can be re-typed or inherit a Gemini config from its app. Managed Agents only accepts
     * Claude model ids, so a non-Claude model must fall back here rather than 400 remotely.
     */
    public function testANonClaudeModelFallsBackToTheDefault(): void
    {
        $agent = $this->makeAgent([
            'config' => ['llm_provider' => 'gemini', 'model' => 'gemini-2.5-flash'],
        ]);

        $this->assertSame(
            AgentSpecBuilderService::DEFAULT_MODEL,
            new AgentSpecBuilderService($agent)->build()->model,
        );
    }

    public function testSystemPromptComposesSoulInstructionsAndOutputFormat(): void
    {
        $agent = $this->makeAgent([
            'soul' => 'You are a hosted teammate.',
            'instructions' => 'Always cite the file you changed.',
            'output_format' => 'Plain text. No preamble.',
        ]);

        $system = (string) new AgentSpecBuilderService($agent)->build()->system;

        $this->assertStringContainsString('You are a hosted teammate.', $system);
        $this->assertStringContainsString('Always cite the file you changed.', $system);
        $this->assertStringContainsString('OUTPUT FORMAT:', $system);
        $this->assertStringContainsString('Plain text. No preamble.', $system);

        // Order matters: who it is, then how it works, then how it answers.
        $this->assertLessThan(
            strpos($system, 'Always cite'),
            strpos($system, 'You are a hosted teammate.'),
        );
    }

    public function testAgentValuesOverrideTheirType(): void
    {
        $type = AgentType::factory()->create([
            'soul' => 'Type-level soul.',
            'instructions' => 'Type-level instructions.',
        ]);

        $agent = $this->makeAgent([
            'agent_type_id' => $type->getId(),
            'soul' => 'Agent-level soul.',
        ]);

        $system = (string) new AgentSpecBuilderService($agent->refresh())->build()->system;

        $this->assertStringContainsString('Agent-level soul.', $system);
        $this->assertStringNotContainsString('Type-level soul.', $system);
        // The agent didn't override instructions, so the type's must still apply.
        $this->assertStringContainsString('Type-level instructions.', $system);
    }

    public function testAnAgentWithNothingConfiguredHasNoSystemPrompt(): void
    {
        $type = AgentType::factory()->create([
            'soul' => null,
            'instructions' => null,
            'output_format' => null,
        ]);

        $agent = $this->makeAgent([
            'agent_type_id' => $type->getId(),
            'soul' => null,
            'instructions' => null,
            'output_format' => null,
        ]);

        $this->assertNull(new AgentSpecBuilderService($agent->refresh())->build()->system);
    }

    /**
     * The API requires a 1–256 char name; catching a blank one here beats a remote validation error.
     */
    public function testABlankNameFallsBackToTheAgentId(): void
    {
        $agent = $this->makeAgent(['name' => '   ']);

        $this->assertSame(
            'Kanvas Agent ' . $agent->getId(),
            new AgentSpecBuilderService($agent)->build()->name,
        );
    }
}
