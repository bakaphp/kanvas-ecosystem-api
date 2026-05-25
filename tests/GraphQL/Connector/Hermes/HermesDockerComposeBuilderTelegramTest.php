<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connector\Hermes;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Connectors\Hermes\Services\DockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class HermesDockerComposeBuilderTelegramTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'user_id' => $user->getId(),
        ]);
    }

    private function makeDeployment(Agent $agent): AgentDeployment
    {
        $deployment = new AgentDeployment();
        $deployment->apps_id = $agent->apps_id;
        $deployment->companies_id = $agent->companies_id;
        $deployment->agent_id = $agent->getId();
        $deployment->agent_machine_id = 1;
        $deployment->system_user = 'agent-' . $agent->slug;
        $deployment->home_directory = '/home/agent-' . $agent->slug;
        $deployment->gateway_port = 21000;
        $deployment->proxy_port = 21001;
        $deployment->container_name = 'hermes-' . $agent->slug;
        $deployment->provider = 'hermes';
        $deployment->status = 'provisioning';
        // Don't save — we only need an in-memory deployment for the builder to read.
        $deployment->exists = true;
        $deployment->id = 999_999;

        return $deployment;
    }

    public function testTelegramTokenAndAllowedUsersAreInjectedIntoDockerComposeEnv(): void
    {
        $app = app(Apps::class);
        $agent = $this->makeAgent();
        $agent->set(AgentChannelTokenEnum::TELEGRAM_BOT_TOKEN->value, '123456789:test-bot-token');
        $agent->set(AgentChannelTokenEnum::TELEGRAM_ALLOWED_USERS->value, '111,222,333');

        $rendered = (new DockerComposeBuilderService())->buildDockerCompose(
            $this->makeDeployment($agent),
            'gateway-token-abc',
            $app,
            $agent,
        );

        $this->assertStringContainsString('- TELEGRAM_BOT_TOKEN=123456789:test-bot-token', $rendered);
        $this->assertStringContainsString('- TELEGRAM_ALLOWED_USERS=111,222,333', $rendered);
    }

    public function testTelegramEnvVarsAreOmittedWhenAgentHasNoTokenSet(): void
    {
        $app = app(Apps::class);
        $agent = $this->makeAgent();

        $rendered = (new DockerComposeBuilderService())->buildDockerCompose(
            $this->makeDeployment($agent),
            'gateway-token-abc',
            $app,
            $agent,
        );

        $this->assertStringNotContainsString('TELEGRAM_BOT_TOKEN', $rendered);
        $this->assertStringNotContainsString('TELEGRAM_ALLOWED_USERS', $rendered);
    }

    public function testTelegramConfigBlockEmittedInRuntimeConfigWhenAppOverrideSet(): void
    {
        $app = app(Apps::class);
        $agent = $this->makeAgent();
        $app->set(ConfigurationEnum::TELEGRAM_CONFIG->value, [
            'require_mention' => true,
            'allow_from' => ['111', '222'],
            'disable_link_previews' => true,
        ]);

        try {
            $yaml = (new DockerComposeBuilderService())->buildRuntimeConfig(
                $agent,
                'gateway-token-abc',
                $app,
            );

            $parsed = Yaml::parse($yaml);

            $this->assertArrayHasKey('telegram', $parsed['platforms']);
            $this->assertSame(['require_mention' => true, 'allow_from' => ['111', '222'], 'disable_link_previews' => true], $parsed['platforms']['telegram']['extra']);
        } finally {
            $app->del(ConfigurationEnum::TELEGRAM_CONFIG->value);
        }
    }

    public function testTelegramConfigBlockOmittedWhenAppOverrideUnset(): void
    {
        $app = app(Apps::class);
        $agent = $this->makeAgent();
        // Make sure no stale value bleeds in from another test
        $app->del(ConfigurationEnum::TELEGRAM_CONFIG->value);

        $yaml = (new DockerComposeBuilderService())->buildRuntimeConfig(
            $agent,
            'gateway-token-abc',
            $app,
        );

        $parsed = Yaml::parse($yaml);

        $this->assertArrayHasKey('slack', $parsed['platforms']);
        $this->assertArrayNotHasKey('telegram', $parsed['platforms']);
    }
}
