<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Factories;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;

class AgentFactory extends Factory
{
    protected $model = Agent::class;

    #[Override]
    public function definition()
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $this->faker->word(),
            'config' => [],
            'agent_type_id' => 1,
            'user_id' => 1,
            'role' => [],
            'is_active' => true,
        ];
    }

    public function withAppId(int $appId)
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function withCompanyId(int $companyId)
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }
}
