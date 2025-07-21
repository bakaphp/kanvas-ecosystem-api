<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Factories;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Override;

class AgentTypeFactory extends Factory
{
    protected $model = AgentType::class;

    #[Override]
    public function definition()
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'config' => [],
            'role' => 'test-role',
            'is_active' => true,
            'is_published' => false,
            'is_multi_agent' => false,
            'multi_agent_list' => [],
        ];
    }

    public function withUserId(int $userId)
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'users_id' => $userId,
            ];
        });
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
