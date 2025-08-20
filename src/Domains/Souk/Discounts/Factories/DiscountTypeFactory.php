<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Override;

class DiscountTypeFactory extends Factory
{
    protected $model = DiscountType::class;

    #[Override]
    public function definition(): array
    {
        $types = ['Percentage', 'Fixed Amount', 'Free Shipping', 'Buy X Get Y'];

        $app = app(Apps::class);
        $appId = $this->states['apps_id'] ?? $app->getId(); // Use the provided app ID if set

        return [
            'apps_id' => $appId,
            'name' => $this->faker->randomElement($types),
            'description' => $this->faker->sentence(),
            'is_deleted' => false,
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
