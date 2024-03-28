<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAttributeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypesAttributes as ProductsTypesAttributesDto;

class ProductTypeService
{
    /**
     * Add a new attribute to a product type.
     *
     * @param UserInterface $user
     * @param array $attributes
     * @param boolean $toVariant
     * @return void
     */
    public static function addAttributes(ProductsTypes $productsTypes, UserInterface $user, array $attributes, bool $toVariant = false): ProductsTypes
    {
        foreach ($attributes as $attribute) {
            $productsAttributesDto = (new ProductsTypesAttributesDto(
                $productsTypes,
                Attributes::getById((int) $attribute['id']),
                $toVariant
            ));

            (new CreateProductTypeAttributeAction($productsAttributesDto, $user))->execute();
        }

        return $productsTypes;
    }
}
