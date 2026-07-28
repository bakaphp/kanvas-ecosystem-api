<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Factories;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Override;

class AgentLlmConfigFactory extends Factory
{
    protected $model = AgentLlmConfig::class;

    #[Override]
    public function definition(): array
    {
        $name = 'LLM Config ' . $this->faker->unique()->word();

        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $name,
            'slug' => Str::slug($name),
            'provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
            'base_uri' => 'https://box.example/v1',
            'api_key' => $this->faker->sha256(),
            'model' => 'Qwen3.6-35B-A3B-4bit',
            'config' => [],
            'is_active' => true,
        ];
    }

    public function withAppId(int $appId): self
    {
        return $this->state(fn (array $attributes) => ['apps_id' => $appId]);
    }

    public function withCompanyId(int $companyId): self
    {
        return $this->state(fn (array $attributes) => ['companies_id' => $companyId]);
    }
}
