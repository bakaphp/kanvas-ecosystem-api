<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypesAttributes as ProductsTypesAttributesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypesAttributes;

class CreateProductTypeAttributeAction
{
    public function __construct(
        protected ProductsTypesAttributesDto $data,
        protected UserInterface $user
    ) {
    }

    public function execute(): ProductsTypesAttributes
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->data->productsTypes->company,
            $this->user
        );

        return ProductsTypesAttributes::firstOrCreate([
            'products_types_id' => $this->data->productsTypes->getId(),
            'attributes_id' => $this->data->attributes->getId(),
            'to_variant' => $this->data->toVariant,
        ]);
    }
}
