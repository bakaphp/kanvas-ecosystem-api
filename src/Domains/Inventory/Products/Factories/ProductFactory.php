<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Factories;

use Baka\Traits\KanvasFactoryStateTrait;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductFactory extends Factory
{
    use KanvasFactoryStateTrait;

    protected $model = Products::class;

    public function definition()
    {
        $app = app(Apps::class);
        $company = Companies::where('id', $this->states['companies_id'] ?? null)->first() ?? Companies::factory()->create();
        $productType = ProductsTypes::factory()->company($company->getId())->create();

        return [
            'name'              => $this->faker->name,
            'apps_id'           => $app->getId(),
            'companies_id'      => $company->getId(),
            'users_id'          => $company->users_id,
            'products_types_id' => $productType->getId(),
            'description'       => $this->faker->text,
            //'sku' => $this->faker->numberBetween(1000, 9000),
        ];
    }

    public function withUserId(int $userId)
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'users_id' => $userId,
            ];
        });
    }

    public function withAppId(int $appId)
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function withCompanyId(int $companyId)
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }
}
