<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingSetupCheck;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeReadinessService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Mockery;
use Tests\TestCase;

/**
 * What the agent is told is missing before it can run a coding job.
 *
 * Only the settings half is covered — `hostProblems()` opens an ssh connection to a real machine and
 * there is no seam for one. The settings half is where the prerequisites actually accumulate, and each
 * case below is a failure found the slow way on a fresh server: dispatch, wait, read the error off a
 * session row, fix one thing, repeat.
 *
 * The app is a mock, not `app(Apps::class)`. An app setting is written to Redis before the database
 * and rolls back never, so configuring the real one here leaks into every later test in the run — it
 * broke `SessionConfigBuilderTest`'s default-npm assertion exactly that way. The service reads
 * `get()` and `getId()` and nothing else, so involving shared state buys nothing.
 */
class CodingRuntimeReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testAFullyConfiguredAppAndAgentReportsNothingMissing(): void
    {
        $this->assertSame([], $this->problems());
    }

    public function testAMissingGitTokenIsNamedWithTheSettingAndWhoSetsIt(): void
    {
        $problems = $this->problems(agentOverrides: [AgentCustomFieldEnum::GIT_TOKEN->value => '']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('CODING_GIT_TOKEN', $problems[0]);
        $this->assertStringContainsString('admin', $problems[0]);
    }

    /**
     * The failure that cost the most time on the new server: with neither set, `SessionConfigBuilder`
     * cannot declare the provider and every turn dies on `ModelUnavailableError`, which names nothing
     * that is actually missing.
     */
    public function testNeitherProviderNpmNorBaseUrlIsReported(): void
    {
        $problems = $this->problems([ConfigurationEnum::PROVIDER_NPM->value => '']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('ModelUnavailableError', $problems[0]);
    }

    public function testABaseUrlAloneIsEnough(): void
    {
        $this->assertSame([], $this->problems([
            ConfigurationEnum::PROVIDER_NPM->value => '',
            ConfigurationEnum::PROVIDER_BASE_URL->value => 'https://api.openai.com/v1',
        ]));
    }

    public function testAKeyOnTheAgentSatisfiesTheAppHavingNone(): void
    {
        $this->assertSame([], $this->problems(
            [ConfigurationEnum::PROVIDER_API_KEY->value => ''],
            [AgentCustomFieldEnum::PROVIDER_API_KEY->value => 'sk-agent-scoped'],
        ));
    }

    public function testNoKeyAnywhereIsReported(): void
    {
        $problems = $this->problems([ConfigurationEnum::PROVIDER_API_KEY->value => '']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('provider API key', $problems[0]);
    }

    public function testTheAgentsOwnModelCoversAMissingAppModel(): void
    {
        $this->assertSame([], $this->problems(
            [ConfigurationEnum::MODEL->value => ''],
            [AgentCustomFieldEnum::MODEL->value => 'gpt-6-luna'],
        ));
    }

    public function testAMissingImageIsNamedWithTheCommandThatFixesIt(): void
    {
        $problems = $this->problems([ConfigurationEnum::IMAGE->value => '']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('opencode_image', $problems[0]);
        $this->assertStringContainsString('kanvas:coding:setup', $problems[0]);
    }

    /**
     * The list Max signed off on, as the settings that decide whether a coding agent can work at all.
     * A checklist that silently omits one is the memory game this replaced: the setting is absent, the
     * answer says nothing about it, and the person is back to guessing.
     */
    public function testEverySettingThatDecidesWhetherItWorksIsOnTheChecklist(): void
    {
        $reported = array_map(
            static fn (CodingSetupCheck $check): string => $check->setting,
            $this->checks()
        );

        foreach ([
            AgentCustomFieldEnum::GIT_TOKEN->value,
            AgentCustomFieldEnum::MACHINE_ID->value,
            AgentCustomFieldEnum::MODEL->value,
            ConfigurationEnum::IMAGE->value,
            ConfigurationEnum::MODEL->value,
            ConfigurationEnum::PROVIDER_API_KEY->value,
            ConfigurationEnum::PROVIDER_BASE_URL->value,
            ConfigurationEnum::PROVIDER_ENV_VAR->value,
            ConfigurationEnum::PROVIDER_ID->value,
            ConfigurationEnum::PROVIDER_NPM->value,
            ConfigurationEnum::WORKSPACE_ROOT->value,
        ] as $setting) {
            $this->assertContains($setting, $reported, $setting . ' is not reported at all.');
        }
    }

    /**
     * Five of the settings have a working fallback, so failing on them would report a healthy install as
     * broken — and omitting them would hide that a step is still open. They are reported as defaults.
     */
    public function testSettingsWithAWorkingDefaultAreReportedRatherThanFailed(): void
    {
        $defaulted = [];

        foreach ($this->checks() as $check) {
            if ($check->usingDefault !== null) {
                $defaulted[$check->setting] = $check->usingDefault;
            }
        }

        $this->assertSame([], $this->problems());
        $this->assertArrayHasKey(AgentCustomFieldEnum::MACHINE_ID->value, $defaulted);
        $this->assertArrayHasKey(AgentCustomFieldEnum::MODEL->value, $defaulted);
        $this->assertArrayHasKey(ConfigurationEnum::PROVIDER_BASE_URL->value, $defaulted);
        $this->assertSame('OPENAI_API_KEY', $defaulted[ConfigurationEnum::PROVIDER_ENV_VAR->value]);
        $this->assertSame('/srv/kanvas', $defaulted[ConfigurationEnum::WORKSPACE_ROOT->value]);
    }

    public function testAnExplicitWorkspaceRootIsReportedAsSetNotAsTheDefault(): void
    {
        $checks = $this->checks([ConfigurationEnum::WORKSPACE_ROOT->value => '/mnt/work']);
        $root = $this->check($checks, ConfigurationEnum::WORKSPACE_ROOT->value);

        $this->assertTrue($root->ok);
        $this->assertNull($root->usingDefault);
    }

    public function testAMachineOnTheAgentIsReportedAsSetNotAsTheFallback(): void
    {
        $checks = $this->checks(agentOverrides: [AgentCustomFieldEnum::MACHINE_ID->value => '78']);

        $this->assertNull($this->check($checks, AgentCustomFieldEnum::MACHINE_ID->value)->usingDefault);
    }

    /**
     * @param list<CodingSetupCheck> $checks
     */
    private function check(array $checks, string $setting): CodingSetupCheck
    {
        foreach ($checks as $check) {
            if ($check->setting === $setting) {
                return $check;
            }
        }

        $this->fail($setting . ' is not on the checklist.');
    }

    /**
     * Mockery partial, matching how this suite mocks every other Eloquent model — PHPUnit's
     * createMock() on a model raises a notice per test for mocking a concrete class.
     *
     * @template T of Apps|Companies
     * @param class-string<T> $class
     * @param array<string, string> $settings
     * @return T
     */
    private function settingsHolder(string $class, array $settings): Apps|Companies
    {
        $holder = Mockery::mock($class)->makePartial();
        $holder->shouldReceive('getId')->andReturn(4242);
        $holder->shouldReceive('get')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default
        );

        return $holder;
    }

    /**
     * @param array<string, string> $appOverrides
     * @param array<string, string> $agentOverrides
     * @return list<string>
     */
    private function problems(array $appOverrides = [], array $agentOverrides = []): array
    {
        return new CodingRuntimeReadinessService()->failures($this->checks($appOverrides, $agentOverrides));
    }

    /**
     * @param array<string, string> $appOverrides
     * @param array<string, string> $agentOverrides
     * @return list<CodingSetupCheck>
     */
    private function checks(array $appOverrides = [], array $agentOverrides = []): array
    {
        $settings = [
            ConfigurationEnum::IMAGE->value => 'kanvas/opencode:2.0.16',
            ConfigurationEnum::PROVIDER_ID->value => 'oai',
            ConfigurationEnum::MODEL->value => 'gpt-6-luna',
            ConfigurationEnum::PROVIDER_API_KEY->value => 'sk-app-scoped',
            ConfigurationEnum::PROVIDER_NPM->value => '@ai-sdk/openai',
            ...$appOverrides,
        ];

        $app = $this->settingsHolder(Apps::class, $settings);

        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        // The service reads the app and company off the agent, so the mocks are injected as loaded
        // relations rather than passed alongside it — which is the point: no caller can hand it an app
        // that disagrees with the agent's own. The company is mocked for the same reason the app is:
        // a provider key stored against the real one would silently satisfy the no-key cases.
        $agent->setRelation('app', $app);
        $agent->setRelation('company', $this->settingsHolder(Companies::class, []));

        foreach ([
            AgentCustomFieldEnum::GIT_TOKEN->value => 'ghp-test',
            AgentCustomFieldEnum::MODEL->value => '',
            AgentCustomFieldEnum::PROVIDER_API_KEY->value => '',
            ...$agentOverrides,
        ] as $key => $value) {
            $agent->set($key, $value);
        }

        return new CodingRuntimeReadinessService()->checksForAgent($agent);
    }
}
