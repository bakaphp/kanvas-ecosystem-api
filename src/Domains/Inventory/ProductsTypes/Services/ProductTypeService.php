<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAttributeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypesAttributes as ProductsTypesAttributesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductTypeService
{
    /**
     * Add a new attribute to a product type.
     */
    public static function addAttributes(ProductsTypes $productsTypes, UserInterface $user, array $attributes, bool $toVariant = false): ProductsTypes
    {
        foreach ($attributes as $attribute) {
            $attributeObject = Attributes::getById((int) $attribute['id']);
            $productsAttributesDto = (
                new ProductsTypesAttributesDto(
                    $productsTypes,
                    $attributeObject,
                    $toVariant
                ));

            (new CreateProductTypeAttributeAction($productsAttributesDto, $user))->execute();

            if ($attributeObject->attributeType->isList()) {
                (new AddAttributeValue($attributeObject, [$attribute]))->execute();
            }
        }

        return $productsTypes;
    }
}
