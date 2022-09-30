<?php

namespace Kanvas\Roles\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Roles\Models\Roles;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\Apps\Apps\Models\Apps;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class RolesFactory extends Factory
{
    /**
    * The name of the factory's corresponding model.
    *
    * @var Apps
    */
    protected $model = Roles::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $app = Apps::first() ?? Apps::factory(1)->create()->first();

        return [
            "companies_id" => 1,
            "apps_id" => $app->id,
            "name" => $this->faker->name(),
            "description" => $this->faker->sentence(3),
            "scope" => 0,
            "is_active" => 1,
            "is_default" => 1,
            "is_deleted" => 0
        ];
    }
}
