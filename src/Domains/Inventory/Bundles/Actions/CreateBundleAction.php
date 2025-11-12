<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\Actions;

use Baka\Support\Str;
use Kanvas\Inventory\Bundles\DataTransferObject\Bundle;
use Kanvas\Inventory\Bundles\Models\Bundle as Bundles;
use Kanvas\Inventory\Variants\Models\Variants;

class CreateBundleAction
{
    public function __construct(
        private Bundle $bundle
    ) {
    }

    public function execute(): Bundles
    {
        $bundle = Bundles::create([
            'apps_id' => $this->bundle->app->getId(),
            'companies_id' => $this->bundle->company->getId(),
            'users_id' => $this->bundle->user->getId(),
            'name' => $this->bundle->name,
            'variant_id' => $this->bundle->variant?->getId(),
            'description' => $this->bundle->description,
            'execution_mode' => $this->bundle->execution_mode,
            'expose_as_product' => $this->bundle->expose_as_product,
            "weight" => $this->bundle->weight,
            'slug' => $this->bundle->slug ?? Str::slug($this->bundle->name)
        ]);
        if (! empty($this->bundle->variants)) {
            $bundle->variants()->sync(
                collect($this->bundle->variants)->mapWithKeys(function ($variant) use ($bundle) {
                    $variantModel = Variants::getById($variant['id'], $bundle->app);
                    return [
                        $variantModel->getId() => [
                            'quantity' => $variant['quantity'] ?? 1,
                            'unit' => $variant['unit'] ?? 'unit',
                            "weight" => $variant["weight"] ?? 0
                        ]
                    ];
                })->toArray()
            );
        }
        $bundle->refresh();

        return $bundle;
    }
}
