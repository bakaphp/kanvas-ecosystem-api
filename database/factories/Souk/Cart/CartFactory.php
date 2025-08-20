<?php

declare(strict_types=1);

namespace Database\Factories\Souk\Cart;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
            'session_id' => $this->faker->unique()->regexify('[a-zA-Z0-9]{32}'),
            'email' => $this->faker->optional(0.8)->email(),
            'payment_intent_id' => $this->faker->optional(0.3)->regexify('pi_[a-zA-Z0-9]{24}'),
            'client_secret' => $this->faker->optional(0.3)->regexify('pi_[a-zA-Z0-9]{24}_secret_[a-zA-Z0-9]{8}'),
            'amount' => $this->faker->optional(0.6)->randomFloat(2, 10, 500),
            'currency' => $this->faker->randomElement(['usd', 'eur', 'gbp']),
            'status' => $this->faker->randomElement(['pending', 'abandoned', 'recovered', 'completed']),
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
            'status' => 'pending',
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'abandoned',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    public function withPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_intent_id' => $this->faker->regexify('pi_[a-zA-Z0-9]{24}'),
            'client_secret' => $this->faker->regexify('pi_[a-zA-Z0-9]{24}_secret_[a-zA-Z0-9]{8}'),
            'amount' => $this->faker->randomFloat(2, 10, 500),
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
