<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Factories;

use Baka\Traits\KanvasFactoryStateTrait;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductTypeFactory extends Factory
{
    use KanvasFactoryStateTrait;
    protected $model = ProductsTypes::class;

    public function definition()
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();

        return [
            'name' => $this->faker->name,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $company->users_id,
            'description' => $this->faker->text,
            'is_published' => 1,
            'weight' => 0,
        ];
    }

}
