<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Services\DockerComposeBuilderService as HermesBuilderService;
use Kanvas\Connectors\OpenClaw\Actions\UpdateDeploymentConfigAction;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Container agents were the largest Gemini line: Pro by default, a Pro fallback, and OpenClaw's own
 * heartbeat re-reading the full main session every 30 minutes. These pin the cheaper defaults.
 */
class AgentRuntimeConfigCostTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function defaults(Apps $app): array
    {
        $user = auth()->user();

        $agent = Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'user_id' => $user->getId(),
        ]);

        $config = json_decode(
            new DockerComposeBuilderService()->buildRuntimeConfig($agent, 'token', $app),
            true,
        );

        return $config['agents']['defaults'];
    }

    public function testDefaultsToFlashWithNoProFallback(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::DEFAULT_MODEL->value);

        $defaults = $this->defaults($app);

        $this->assertSame('google/gemini-3-flash-preview', $defaults['model']['primary']);
        $this->assertSame(['google/gemini-3.1-flash-lite-preview'], $defaults['model']['fallbacks']);
    }

    public function testTheAppSettingStillOverridesTheModel(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::DEFAULT_MODEL->value, 'google/gemini-3.1-flash-lite-preview');

        $this->assertSame('google/gemini-3.1-flash-lite-preview', $this->defaults($app)['model']['primary']);

        $app->del(ConfigurationEnum::DEFAULT_MODEL->value);
    }

    public function testHeartbeatIsIsolatedLightAndCheap(): void
    {
        $defaults = $this->defaults(app(Apps::class));

        $this->assertSame('2h', $defaults['heartbeat']['every']);
        $this->assertTrue($defaults['heartbeat']['isolatedSession']);
        $this->assertTrue($defaults['heartbeat']['lightContext']);
        $this->assertSame('google/gemini-3.1-flash-lite-preview', $defaults['heartbeat']['model']);
    }

    public function testSessionsCompactBelowTheLongContextTier(): void
    {
        $defaults = $this->defaults(app(Apps::class));

        $this->assertLessThan(200_000, $defaults['contextTokens']);
        $this->assertSame('safeguard', $defaults['compaction']['mode']);
        $this->assertSame('cache-ttl', $defaults['contextPruning']['mode']);
    }

    public function testLivePatchSwapsProForFlashEverywhere(): void
    {
        $patch = BaseDockerComposeBuilderService::costPatchFor([
            'agents' => [
                'defaults' => ['model' => ['primary' => 'google/gemini-3.1-pro-preview']],
                'list' => [
                    ['id' => 'jessica', 'model' => 'google/gemini-3.1-pro-preview'],
                    ['id' => 'helper', 'model' => ['primary' => 'google/gemini-2.5-pro']],
                ],
            ],
        ]);

        $this->assertSame(
            BaseDockerComposeBuilderService::DEFAULT_MODEL,
            $patch['agents']['defaults']['model']['primary']
        );
        $this->assertSame([BaseDockerComposeBuilderService::CHEAP_MODEL], $patch['agents']['defaults']['model']['fallbacks']);
        $this->assertSame(BaseDockerComposeBuilderService::DEFAULT_MODEL, $patch['agents']['list'][0]['model']);
        $this->assertSame('jessica', $patch['agents']['list'][0]['id'], 'The rest of the entry is kept.');
        $this->assertSame(BaseDockerComposeBuilderService::DEFAULT_MODEL, $patch['agents']['list'][1]['model']['primary']);
        $this->assertSame('2h', $patch['agents']['defaults']['heartbeat']['every']);
    }

    public function testLivePatchKeepsADeliberateNonProModel(): void
    {
        $patch = BaseDockerComposeBuilderService::costPatchFor([
            'agents' => [
                'defaults' => ['model' => 'anthropic/claude-sonnet-4-6'],
                'list' => [['id' => 'coder', 'model' => 'anthropic/claude-sonnet-4-6']],
            ],
        ]);

        $this->assertSame('anthropic/claude-sonnet-4-6', $patch['agents']['defaults']['model']['primary']);
        $this->assertSame('anthropic/claude-sonnet-4-6', $patch['agents']['list'][0]['model']);
    }

    /**
     * An associative decode turns `{}` into `[]`, so every config round trip used to rewrite the
     * runtime's empty-object entries as lists.
     */
    public function testConfigRoundTripKeepsEmptyObjects(): void
    {
        $action = new class (new AgentDeployment(), '{}') extends UpdateDeploymentConfigAction {
            public function roundTrip(string $raw, string $patch): string
            {
                return $this->encodeConfig($this->deepMerge($this->decodeConfig($raw), $this->decodeConfig($patch)));
            }
        };

        $result = $action->roundTrip(
            '{"agents":{"defaults":{"models":{"google/gemini-2.5-pro":{}}}},"skills":{"entries":{}}}',
            (string) json_encode(BaseDockerComposeBuilderService::costPatchFor([])),
        );

        $this->assertStringContainsString('"google/gemini-2.5-pro": {}', $result);
        $this->assertStringContainsString('"entries": {}', $result);
        $this->assertStringContainsString('"google/gemini-3-flash-preview": {}', $result);
    }

    /**
     * Hermes talks to Gemini directly and its config carries no session knobs, so the model is the
     * only thing to move — and a non-Pro model it is deliberately paired with is left alone.
     */
    public function testHermesPatchesTheModelOnlyAndNormalizesIt(): void
    {
        $patch = Yaml::parse(new HermesBuilderService()->costDefaultsPatch(
            Yaml::dump(['model' => ['default' => 'gemini-3.1-pro-preview', 'provider' => 'gemini']])
        ));

        $this->assertSame(['model'], array_keys($patch), 'Hermes has no heartbeat/compaction to set.');
        // The `google/` prefix is stripped: Hermes names the provider in its own field.
        $this->assertSame('gemini-3-flash-preview', $patch['model']['default']);
        $this->assertSame('gemini', $patch['model']['provider']);
    }

    public function testHermesLeavesANonProModelAlone(): void
    {
        $this->assertSame('', new HermesBuilderService()->costDefaultsPatch(
            Yaml::dump(['model' => ['default' => 'claude-sonnet-4-6', 'provider' => 'anthropic']])
        ));
    }

    /**
     * `--deployment=0` once read as "no filter" and swept every running deployment over SSH.
     */
    public function testAnIdOfZeroMatchesNoDeployment(): void
    {
        $this->artisan('kanvas:agent-runtime-apply-cost-defaults', ['--deployment' => '0', '--dry-run' => true])
            ->expectsConfirmation('Read the config of 0 running deployment(s) over SSH (read-only). Continue?', 'yes')
            ->expectsOutput('0 deployment(s) processed, 0 already cheap, 0 failed.')
            ->assertSuccessful();
    }

    public function testAnUnknownProviderIsRejectedBeforeAnySsh(): void
    {
        $this->artisan('kanvas:agent-runtime-apply-cost-defaults', ['--provider' => 'claude', '--dry-run' => true])
            ->assertFailed();
    }
}
