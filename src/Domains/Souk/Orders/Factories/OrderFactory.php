<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Override;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Orders\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    #[Override]
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'apps_id' => function (): int {
                return app(Apps::class)->getId();
            },
            'companies_id' => 0,
            'region_id' => 1,
            'people_id' => function (array $attributes) {
                return People::factory();
            },
            'order_number' => $this->faker->numberBetween(1000, 9999),
            'total_gross_amount' => $this->faker->randomFloat(2, 50, 500),
            'total_net_amount' => $this->faker->randomFloat(2, 40, 450),
            'shipping_price_gross_amount' => $this->faker->randomFloat(2, 0, 50),
            'shipping_price_net_amount' => $this->faker->randomFloat(2, 0, 45),
            'discount_amount' => 0,
            'tax_amount' => $this->faker->randomFloat(2, 5, 50),
            'status' => 'draft',
            'fulfillment_status' => 'pending',
            'display_gross_prices' => true,
            'currency' => 'USD',
            'is_deleted' => false,
        ];
    }

    public function withAppId(int $appId): static
    {
        return $this->state(fn () => [
            'apps_id' => $appId,
        ]);
    }

    public function withCompanyId(int $companyId): static
    {
        return $this->state(fn () => [
            'companies_id' => $companyId,
        ]);
    }

    public function withUserId(int $userId): static
    {
        return $this->state(fn () => [
            'users_id' => $userId,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'fulfillment_status' => 'fulfilled',
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'fulfillment_status' => 'pending',
        ]);
    }

    public function withPeopleId(int $peopleId)
    {
        return $this->state(function (array $attributes) use ($peopleId) {
            return [
                'people_id' => $peopleId,
            ];
        });
    }
}
