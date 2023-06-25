<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;

class Variants
{
    /**
     * Create a new product variants.
     *
     * @param Products $product
     * @param array $variants
     * @return array
     */
    public static function createVariantsFromArray(Products $product, array $variants, UserInterface $user): array
    {
        $variantsData = [];

        foreach ($variants as $variant) {
            $variantDto = VariantsDto::from([
                'product' => $product,
                'products_id' => $product->getId(),
                ...$variant,
            ]);

            $variantModel = (new CreateVariantsAction($variantDto, $user))->execute();
            if (isset($variant['attributes'])) {
                $variantModel->addAttributes($user, $variant['attributes']);
            }

            $variantsData[] = $variantModel;
        }

        return $variantsData;
    }
}
