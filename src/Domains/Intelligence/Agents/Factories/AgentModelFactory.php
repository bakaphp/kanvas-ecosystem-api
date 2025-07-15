<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Factories;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Override;

class AgentModelFactory extends Factory
{
    protected $model = AgentModel::class;

    #[Override]
    public function definition()
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $this->faker->word(),
            'config' => [],
            'is_active' => true,
            'is_published' => false,
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
}
