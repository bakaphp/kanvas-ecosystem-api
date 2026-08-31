<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Factories;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
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
            'agent_type_id' => AgentType::factory(),
            // NEVER a hardcoded id. On a seeded database user 1 is nobody, but on a fresh one it is
            // the acting test user — so the agent and the "human" in a test become the same row and
            // every "is this actor an agent?" check inverts: self-approval fires on a real person, a
            // human comment reads as agent-authored and wakes no one. Its own user, like production.
            'user_id' => Users::factory(),
            'created_by_users_id' => fn (array $attributes) => $attributes['user_id'],
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
