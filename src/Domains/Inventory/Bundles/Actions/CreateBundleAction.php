<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\Actions;

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
            'app_id' => $this->bundle->app->getId(),
            'company_id' => $this->bundle->company->getId(),
            'user_id' => $this->bundle->user->getId(),
            'name' => $this->bundle->name,
            'variant_id' => $this->bundle->variant?->getId(),
            'description' => $this->bundle->description,
            'execution_mode' => $this->bundle->execution_mode,
            'expose_as_product' => $this->bundle->expose_as_product,
        ]);
        if (! empty($this->bundle->variants)) {
            $bundle->variants()->sync(
                collect($this->bundle->variants)->mapWithKeys(function ($variant) use ($bundle) {
                    $variantModel = Variants::getById($variant['id'], $bundle->app);
                    return [
                        $variantModel->getId() => [
                            'quantity' => $variant['quantity'] ?? 1,
                            'unit' => $variant['unit'] ?? 'unit',
                        ]
                    ];
                })->toArray()
            );
        }
        $bundle->refresh();

        return $bundle;
    }
}
