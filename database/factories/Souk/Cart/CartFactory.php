<?php

declare(strict_types=1);

namespace Database\Factories\Souk\Cart;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Cart\Enums\CartStatusEnum;
use Kanvas\Souk\Cart\Models\Cart;
use Kanvas\Users\Models\Users;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kanvas\Souk\Cart\Models\Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'apps_id' => Apps::factory(),
            'companies_id' => Companies::factory(),
            'users_id' => $this->faker->boolean(70) ? Users::factory() : null,
            'cart_session_id' => $this->faker->unique()->regexify('[a-zA-Z0-9]{32}'),
            'email' => $this->faker->optional(0.8)->email(),
            'amount' => $this->faker->optional(0.6)->randomFloat(2, 10, 500),
            'currency' => $this->faker->randomElement(['usd', 'eur', 'gbp']),
            'status' => $this->faker->randomElement([
                CartStatusEnum::PENDING->value,
                CartStatusEnum::ABANDONED->value,
                CartStatusEnum::RECOVERED->value,
                CartStatusEnum::COMPLETED->value,
            ]),
            'metadata' => $this->faker->optional(0.7)->randomElement([
                ['source' => 'web', 'utm_campaign' => 'summer_sale'],
                ['source' => 'mobile', 'app_version' => '2.1.0'],
                ['source' => 'b2b', 'account_manager' => 'John Doe'],
                null
            ]),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatusEnum::PENDING->value,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatusEnum::ABANDONED->value,
        ]);
    }

    public function recovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatusEnum::RECOVERED->value,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatusEnum::COMPLETED->value,
        ]);
    }

    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'users_id' => null,
            'email' => $this->faker->email(),
        ]);
    }
}
