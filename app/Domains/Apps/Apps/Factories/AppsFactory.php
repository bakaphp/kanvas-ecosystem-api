<?php

namespace Kanvas\Apps\Apps\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Apps\Models\Apps;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class AppsFactory extends Factory
{
    /**
    * The name of the factory's corresponding model.
    *
    * @var Apps
    */
    protected $model = Apps::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            "url" => $this->faker->url(),
            "key" => Str::random(10),
            "name" => $this->faker->name(),
            "description" => $this->faker->sentence(2),
            "is_actived" => 1,
            "ecosystem_auth" => 1,
            "payments_active" => 1,
            "is_public" => 1,
            "domain_based" => 1,
            "domain" => $this->faker->domainName(),
            "is_deleted" => 0
        ];
    }
}
