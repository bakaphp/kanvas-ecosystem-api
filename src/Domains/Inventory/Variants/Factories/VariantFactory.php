<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Factories;

use Baka\Traits\KanvasFactoryStateTrait;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Override;

class VariantFactory extends Factory
{
    use KanvasFactoryStateTrait;

    protected $model = Variants::class;

    #[Override]
    public function definition()
    {
        $app = app(Apps::class);
        $product = Products::where('id', $this->states['products_id'] ?? null)->first();

        return [
            'name' => $this->faker->words(3, true),
            'apps_id' => $app->getId(),
            'companies_id' => $product?->companies_id ?? $this->states['companies_id'] ?? null,
            'users_id' => $product?->users_id ?? $this->states['users_id'] ?? null,
            'products_id' => $product?->getId() ?? $this->states['products_id'] ?? null,
            'description' => $this->faker->sentence,
            'sku' => $this->faker->unique()->numerify('SKU-#####'),
            'is_published' => true,
        ];
    }

    public function withProductId(int $productId): static
    {
        return $this->state(function (array $attributes) use ($productId) {
            $product = Products::find($productId);

            return [
                'products_id' => $productId,
                'companies_id' => $product?->companies_id ?? $attributes['companies_id'],
                'users_id' => $product?->users_id ?? $attributes['users_id'],
            ];
        });
    }

    public function withUserId(int $userId): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'users_id' => $userId,
            ];
        });
    }

    public function withAppId(int $appId): static
    {
        return $this->state(function (array $attributes) use ($appId) {
            return [
                'apps_id' => $appId,
            ];
        });
    }

    public function withCompanyId(int $companyId): static
    {
        return $this->state(function (array $attributes) use ($companyId) {
            return [
                'companies_id' => $companyId,
            ];
        });
    }

    public function published(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_published' => true,
            ];
        });
    }

    public function unpublished(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_published' => false,
            ];
        });
    }
}
