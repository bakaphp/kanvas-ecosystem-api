<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\Actions;

use Kanvas\Inventory\Bundles\DataTransferObject\Bundle;
use Kanvas\Inventory\Bundles\Models\Bundle as Bundles;
use Kanvas\Inventory\Variants\Models\Variants;

class UpdateBundleAction
{
    public function __construct(
        private Bundles $bundleModel,
        private Bundle $bundle
    ) {
    }

    public function execute(): Bundles
    {
        $this->bundleModel->update([
            'name' => $this->bundle->name,
            'weight' => $this->bundle->weight,
            'variant_id' => $this->bundle->variant?->getId(),
            'description' => $this->bundle->description,
            'execution_mode' => $this->bundle->execution_mode,
            'expose_as_product' => $this->bundle->expose_as_product,
        ]);
        $app = $this->bundleModel->app;
        if (! empty($this->bundle->variants)) {
            $this->bundleModel->variants()->sync(
                collect($this->bundle->variants)->mapWithKeys(function ($variant) use ($app) {
                    $variantModel = Variants::getById($variant['id'], $app);
                    return [
                        $variantModel->getId() => [
                            'quantity' => $variant['quantity'] ?? 1,
                            'unit' => $variant['unit'] ?? 'unit',
                            'weight' => $variant["weight"] ?? $variantModel->weight
                        ]
                    ];
                })->toArray()
            );
        }
        $this->bundleModel->refresh();

        return $this->bundleModel;
    }
}
