<?php

namespace Kanvas\SystemModules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Model>
 */
class SystemModulesFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var Apps
     */
    protected $model = SystemModules::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $app = Apps::first() ?? Apps::factory(1)->create()->first();
        return [
            'apps_id' => $app->id,
            'name' => $this->faker->name(),
            'slug' => $this->faker->slug(),
            'model_name' => $this->faker->name(),
            'parents_id' => 0,
            'menu_order' => 0,
            'show' => 1,
            'use_elastic' => 0,
            'browse_fields' => ' ',
            'bulk_actions' => null,
            'mobile_component_type' => null,
            'mobile_navigation_type' => null,
            'mobile_tab_index' => 0,
            'protected' => 0,
            'is_deleted' => 0,
        ];
    }
}
