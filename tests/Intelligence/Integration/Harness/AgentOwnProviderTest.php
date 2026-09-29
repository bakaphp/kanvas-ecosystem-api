<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenCode\Actions\EnsureAgentCodingContainerAction;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingModelResolver;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeReadinessService;
use Kanvas\Connectors\OpenCode\Services\SessionConfigBuilder;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * One agent on its own provider (a frontend agent on OpenRouter) while the app stays on OpenAI.
 *
 * The app is a mock for the reason `CodingRuntimeReadinessTest` gives: a real app setting is written to
 * Redis and never rolls back.
 */
class AgentOwnProviderTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const array APP_SETTINGS = [
        ConfigurationEnum::IMAGE->value => 'kanvas/opencode:2.0.16',
        ConfigurationEnum::PROVIDER_ID->value => 'oai',
        ConfigurationEnum::MODEL->value => 'gpt-6-luna',
        ConfigurationEnum::PROVIDER_NPM->value => '@ai-sdk/openai',
        ConfigurationEnum::PROVIDER_BASE_URL->value => 'https://api.openai.com/v1',
        ConfigurationEnum::PROVIDER_ENV_VAR->value => 'OPENAI_API_KEY',
        ConfigurationEnum::PROVIDER_API_KEY->value => 'sk-app-openai',
    ];

    private const array OPENROUTER = [
        AgentCustomFieldEnum::PROVIDER_ID->value => 'openrouter',
        AgentCustomFieldEnum::PROVIDER_BASE_URL->value => 'https://openrouter.ai/api/v1',
        AgentCustomFieldEnum::PROVIDER_ENV_VAR->value => 'OPENROUTER_API_KEY',
        AgentCustomFieldEnum::PROVIDER_API_KEY->value => 'sk-or-agent',
        AgentCustomFieldEnum::MODEL->value => 'moonshotai/kimi-k3',
    ];

    public function testAnAgentWithoutItsOwnProviderStillUsesTheApps(): void
    {
        $resolver = new CodingModelResolver($this->app(), $this->agent([]));

        $this->assertFalse($resolver->hasOwnProvider());
        $this->assertSame('oai', $resolver->provider());
        $this->assertSame('@ai-sdk/openai', $resolver->npm());
        $this->assertSame('oai/gpt-6-luna', $resolver->reference());
    }

    public function testAnAgentWithItsOwnProviderDeclaresItInsteadOfTheApps(): void
    {
        $config = new SessionConfigBuilder($this->app(), agent: $this->agent(self::OPENROUTER))->toArray();

        $this->assertSame(['openrouter'], array_keys($config['provider']));
        $provider = $config['provider']['openrouter'];
        $this->assertSame(CodingModelResolver::DEFAULT_NPM, $provider['npm']);
        $this->assertSame('https://openrouter.ai/api/v1', $provider['options']['baseURL']);
        $this->assertSame(['OPENROUTER_API_KEY'], $provider['env']);
        $this->assertSame('openrouter/moonshotai/kimi-k3', $config['model']);
        // The lock follows the agent's provider — left on the app's, every turn would be denied.
        $this->assertSame('openrouter', $config['experimental']['policies'][1]['resource']);
    }

    /**
     * Mixing the two would send an OpenRouter model name to OpenAI's host, so an agent's provider takes
     * nothing from the app and fails loudly when its own block is incomplete.
     */
    public function testAnAgentsOwnProviderNeverInheritsTheAppsBaseUrl(): void
    {
        $agent = $this->agent([
            AgentCustomFieldEnum::PROVIDER_ID->value => 'openrouter',
            AgentCustomFieldEnum::MODEL->value => 'moonshotai/kimi-k3',
        ]);

        $this->assertNull(new CodingModelResolver($this->app(), $agent)->baseUrl());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(AgentCustomFieldEnum::PROVIDER_BASE_URL->value);

        new SessionConfigBuilder($this->app(), agent: $agent)->toArray();
    }

    public function testTheAppsKeyIsNeverHandedToAnAgentsOwnProvider(): void
    {
        $agent = $this->agent([...self::OPENROUTER, AgentCustomFieldEnum::PROVIDER_API_KEY->value => '']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no key of its own');

        $this->invoke($this->containerAction($agent), 'resolveApiKey');
    }

    public function testTheContainerIsRebuiltWhenOnlyTheKeysVariableChanges(): void
    {
        $before = $this->invoke($this->containerAction($this->agent(self::OPENROUTER)), 'keyFingerprint');
        $after = $this->invoke(
            $this->containerAction($this->agent([
                ...self::OPENROUTER,
                AgentCustomFieldEnum::PROVIDER_ENV_VAR->value => 'OPENAI_API_KEY',
            ])),
            'keyFingerprint'
        );

        $this->assertNotSame($before, $after);
    }

    public function testReadinessAsksForTheAgentsKeyNotTheApps(): void
    {
        $problems = $this->problems([...self::OPENROUTER, AgentCustomFieldEnum::PROVIDER_API_KEY->value => '']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString(AgentCustomFieldEnum::PROVIDER_API_KEY->value, $problems[0]);
    }

    public function testReadinessAsksForTheAgentsBaseUrl(): void
    {
        $problems = $this->problems([...self::OPENROUTER, AgentCustomFieldEnum::PROVIDER_BASE_URL->value => '']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString(AgentCustomFieldEnum::PROVIDER_BASE_URL->value, $problems[0]);
    }

    public function testAFullyConfiguredAgentProviderNeedsNothingFromTheApp(): void
    {
        $this->assertSame([], $this->problems(self::OPENROUTER, [
            ConfigurationEnum::PROVIDER_ID->value => '',
            ConfigurationEnum::PROVIDER_API_KEY->value => '',
        ]));
    }

    /**
     * @param array<string, string> $agentSettings
     * @param array<string, string> $appOverrides
     * @return list<string>
     */
    private function problems(array $agentSettings, array $appOverrides = []): array
    {
        $agent = $this->agent(
            [AgentCustomFieldEnum::GIT_TOKEN->value => 'ghp-test', ...$agentSettings],
            $appOverrides
        );

        $service = new CodingRuntimeReadinessService();

        return $service->failures($service->checksForAgent($agent));
    }

    private function containerAction(Agent $agent): EnsureAgentCodingContainerAction
    {
        return new EnsureAgentCodingContainerAction($agent, new AgentMachine(), $agent->app);
    }

    private function invoke(object $target, string $method): mixed
    {
        return new ReflectionMethod($target, $method)->invoke($target);
    }

    /**
     * @param array<string, string> $settings
     * @param array<string, string> $appOverrides
     */
    private function agent(array $settings, array $appOverrides = []): Agent
    {
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $agent->setRelation('app', $this->app($appOverrides));
        $agent->setRelation('company', $this->holder(Companies::class, []));

        foreach ($settings as $key => $value) {
            $agent->set($key, $value);
        }

        return $agent;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function app(array $overrides = []): Apps
    {
        return $this->holder(Apps::class, [...self::APP_SETTINGS, ...$overrides]);
    }

    /**
     * @template T of Apps|Companies
     * @param class-string<T> $class
     * @param array<string, string> $settings
     * @return T
     */
    private function holder(string $class, array $settings): Apps|Companies
    {
        $holder = Mockery::mock($class)->makePartial();
        $holder->shouldReceive('getId')->andReturn(4242);
        $holder->shouldReceive('get')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default
        );

        return $holder;
    }
}
