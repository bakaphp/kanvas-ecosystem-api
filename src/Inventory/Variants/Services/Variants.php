<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Models\Variants as ModelVariants;
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
            if(isset($variant['attributes'])) {
               self::addAttributes($user, $variantModel, $variant['attributes']);
            }

            $variantsData[] = $variantModel;
        }

        return $variantsData;
    }

    /**
     * Add/create new attributes from a variant.
     *
     * @param ModelVariants $variants
     * @param array $attributes
     * @return void
     */
    public static function addAttributes(UserInterface $user, ModelVariants $variants, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $attributesDto = AttributesDto::from([
                'app' => app(Apps::class),
                'user' => $user,
                'company' => $variants->product->companies,
                'name' => $attribute['name'],
                'value' => $attribute['value']
            ]);

            $attributeModel = (new CreateAttribute($attributesDto, $user))->execute();
            (new AddAttributeAction($variants, $attributeModel, $attribute['value']))->execute();
        }
    }
}
