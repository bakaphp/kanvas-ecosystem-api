<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Mockery;

/**
 * An agent whose app and company answer settings from an array.
 *
 * The app is a mock for the reason `CodingRuntimeReadinessTest` gives: a real app setting is written to
 * Redis and never rolls back.
 */
trait MocksCodingApp
{
    private const array APP_SETTINGS = [
        ConfigurationEnum::IMAGE->value => 'kanvas/opencode:2.0.16',
        ConfigurationEnum::PROVIDER_ID->value => 'oai',
        ConfigurationEnum::MODEL->value => 'gpt-6-luna',
        ConfigurationEnum::PROVIDER_NPM->value => '@ai-sdk/openai',
        ConfigurationEnum::PROVIDER_BASE_URL->value => 'https://api.openai.com/v1',
        ConfigurationEnum::PROVIDER_ENV_VAR->value => 'OPENAI_API_KEY',
        ConfigurationEnum::PROVIDER_API_KEY->value => 'sk-app-openai',
    ];

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
