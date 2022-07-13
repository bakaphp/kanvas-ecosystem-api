<?php

namespace Kanvas\Currencies\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Currencies\Models\Currencies;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class CurrenciesFactory extends Factory
{
    /**
    * The name of the factory's corresponding model.
    *
    * @var Apps
    */
    protected $model = Currencies::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            "country" => $this->faker->locale(),
            "currency" => $this->faker->currencyCode(),
            "code" => $this->faker->countryCode(),
            "symbol" => $this->faker->locale(),
            "is_deleted" => 0
        ];
    }
}
